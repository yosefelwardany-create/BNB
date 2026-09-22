<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Operations\Enums\TaskKind;
use App\Domain\Operations\Enums\TaskPriority;
use App\Domain\Operations\Enums\TaskStatus;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\Unit;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasCustomFields;
use App\Support\Concerns\HasTags;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A piece of operational work.
 *
 * One table serves cleaning, maintenance, inspections and everything else,
 * because they share a property, a window, an assignee, a status and a cost;
 * only the checklist and a handful of columns differ. Splitting them would
 * mean three copies of scheduling, assignment and reporting.
 *
 * Scheduled times are absolute instants derived from the *property's* local
 * clock, so a cleaner's phone in another timezone still shows the right hour.
 *
 * @property TaskStatus $status
 * @property TaskKind $kind
 * @property TaskPriority $priority
 */
class Task extends BaseModel
{
    use Auditable, BelongsToOrganization, HasCustomFields, HasFactory, HasTags, SoftDeletes;

    protected $fillable = [
        'organization_id', 'reference', 'kind', 'title', 'description',
        'property_id', 'unit_id', 'reservation_id',
        'status', 'priority',
        'assigned_to_id', 'team_id', 'vendor_id',
        'scheduled_start', 'scheduled_end', 'due_at', 'estimated_minutes',
        'estimated_cost', 'actual_cost', 'currency', 'billable_to',
        'issue_category', 'severity', 'affects_availability', 'sla_due_at',
        'recurrence_id', 'is_recurring_template',
        'generated_by', 'generation_key',
        'metadata', 'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => TaskKind::class,
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'scheduled_start' => 'immutable_datetime',
            'scheduled_end' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'sla_due_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'affects_availability' => 'boolean',
            'is_billed' => 'boolean',
            'is_recurring_template' => 'boolean',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'status' => 'pending',
        'priority' => 'normal',
        'affects_availability' => false,
        'is_billed' => false,
        'is_recurring_template' => false,
    ];

    // ------------------------------------------------------------------
    // Relationships
    // ------------------------------------------------------------------

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('position');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->orderBy('created_at');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(TaskPhoto::class);
    }

    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(TaskRecurrence::class, 'recurrence_id');
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value]);
    }

    public function scopeOfKind(Builder $query, string|array $kind): Builder
    {
        return $query->whereIn('kind', (array) $kind);
    }

    public function scopeAssignedTo(Builder $query, string $userId): Builder
    {
        return $query->where('assigned_to_id', $userId);
    }

    /**
     * Work scheduled within a window. Used by the operations board and by the
     * staff app's "today" view.
     */
    public function scopeScheduledBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->where(function (Builder $q) use ($from, $to): void {
            $q->whereBetween('scheduled_start', [$from, $to])
                ->orWhereBetween('due_at', [$from, $to]);
        });
    }

    /** Open work whose deadline has passed. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assigned_to_id')
            ->whereNull('team_id')
            ->whereNull('vendor_id');
    }

    /**
     * Narrow a listing to what one person is allowed to see.
     *
     * This is the query-side counterpart of the task policy. The policy
     * answers "may this person open this task?"; a listing cannot ask that of
     * every row, so the same rule is expressed once more as a predicate.
     * Keeping the two in step is the cost of not returning a page of records
     * the caller may not read.
     *
     * @param  list<string>|null  $teamIds  Teams the viewer belongs to.
     * @param  list<string>|null  $propertyIds  Null means unrestricted.
     */
    public function scopeVisibleTo(
        Builder $query,
        string $userId,
        bool $canSeeAll,
        ?array $teamIds = null,
        ?array $propertyIds = null,
    ): Builder {
        if ($propertyIds !== null) {
            $query->whereIn('property_id', $propertyIds);
        }

        if ($canSeeAll) {
            return $query;
        }

        // Only their own rota: assigned to them, or to a crew they are in.
        return $query->where(function (Builder $q) use ($userId, $teamIds): void {
            $q->where('assigned_to_id', $userId);

            if ($teamIds !== null && $teamIds !== []) {
                $q->orWhereIn('team_id', $teamIds);
            }
        });
    }

    // ------------------------------------------------------------------
    // Behaviour
    // ------------------------------------------------------------------

    public function estimatedCost(): ?Money
    {
        return $this->estimated_cost === null || $this->currency === null
            ? null
            : Money::of((int) $this->estimated_cost, $this->currency);
    }

    public function actualCost(): ?Money
    {
        return $this->actual_cost === null || $this->currency === null
            ? null
            : Money::of((int) $this->actual_cost, $this->currency);
    }

    public function isAssigned(): bool
    {
        return $this->assigned_to_id !== null
            || $this->team_id !== null
            || $this->vendor_id !== null;
    }

    public function isOverdue(): bool
    {
        return $this->status->isOpen()
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /**
     * Whether the work is late against its service level, which is a stronger
     * signal than simply being past its scheduled window.
     */
    public function breachesSla(): bool
    {
        return $this->status->isOpen()
            && $this->sla_due_at !== null
            && $this->sla_due_at->isPast();
    }

    /**
     * How long the work actually took, once it has been done.
     */
    public function durationMinutes(): ?int
    {
        if ($this->started_at === null || $this->completed_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInMinutes($this->completed_at);
    }

    /**
     * Progress through the checklist, as a percentage.
     */
    public function checklistProgress(): float
    {
        $items = $this->relationLoaded('checklistItems')
            ? $this->checklistItems
            : $this->checklistItems()->get();

        if ($items->isEmpty()) {
            return $this->status === TaskStatus::Completed ? 100.0 : 0.0;
        }

        $done = $items->reject(fn (TaskChecklistItem $item): bool => $item->status === 'pending')->count();

        return round(($done / $items->count()) * 100, 1);
    }

    /**
     * Checklist items that failed, which is what turns an inspection into
     * follow-up maintenance.
     */
    public function failedChecklistItems(): Collection
    {
        return $this->checklistItems()->where('status', 'failed')->get();
    }

    /**
     * The scheduled window rendered in the property's own timezone, which is
     * how it must be shown to whoever is doing the work.
     */
    public function localScheduledStart(): ?CarbonImmutable
    {
        if ($this->scheduled_start === null || $this->property === null) {
            return $this->scheduled_start;
        }

        return $this->scheduled_start->setTimezone($this->property->timezone);
    }
}
