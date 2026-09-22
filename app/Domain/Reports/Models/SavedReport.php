<?php

declare(strict_types=1);

namespace App\Domain\Reports\Models;

use App\Domain\Reports\DataObjects\ReportParameters;
use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A report somebody set up once and wants again.
 *
 * Holds a report key and parameters, never a query — see the migration for why
 * that boundary matters.
 *
 * Private by default. A saved report is usually somebody's own working view
 * before it is anybody else's, and a list where every colleague's experiments
 * appear is a list nobody reads.
 */
class SavedReport extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'name', 'description', 'report_key', 'parameters',
        'is_shared', 'schedule_cron', 'schedule_timezone', 'recipients',
        'format', 'is_active', 'metadata', 'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'recipients' => 'array',
            'metadata' => 'array',
            'is_shared' => 'boolean',
            'is_active' => 'boolean',
            'run_count' => 'integer',
            'last_run_at' => 'immutable_datetime',
            'next_run_at' => 'immutable_datetime',
        ];
    }

    protected $attributes = [
        'is_shared' => false,
        'is_active' => true,
        'format' => 'csv',
        'run_count' => 0,
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Reports a given user may see: their own, plus anything shared.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('created_by_id', $user->getKey())
            ->orWhere('is_shared', true));
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNotNull('schedule_cron');
    }

    public function scopeDue(Builder $query, ?string $at = null): Builder
    {
        return $query->scheduled()
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $at ?? now());
    }

    /**
     * The parameters, resolved for a run happening now.
     *
     * Relative periods are turned into dates here rather than at save time, so
     * "last month" means last month whenever the report runs.
     */
    public function parameters(): ReportParameters
    {
        return ReportParameters::fromArray($this->parameters ?? []);
    }

    public function isScheduled(): bool
    {
        return $this->schedule_cron !== null && $this->is_active;
    }

    /**
     * Whether this report delivers to anybody.
     *
     * A schedule with no recipients runs and produces nothing anybody sees,
     * which looks identical to a schedule that is broken.
     */
    public function hasRecipients(): bool
    {
        return ! blank($this->recipients);
    }
}
