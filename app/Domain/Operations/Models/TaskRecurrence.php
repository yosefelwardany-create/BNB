<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\Unit;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A repeating piece of work: a weekly garden visit, a quarterly boiler
 * service, a monthly deep clean.
 *
 * `last_generated_on` is what keeps generation idempotent — the scheduler can
 * run as often as it likes without creating the same job twice.
 */
class TaskRecurrence extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'property_id', 'unit_id', 'name', 'kind',
        'frequency', 'interval', 'days_of_week', 'day_of_month', 'time_of_day',
        'checklist_template_id', 'team_id', 'assigned_to_id', 'vendor_id',
        'estimated_minutes', 'starts_on', 'ends_on', 'last_generated_on', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'days_of_week' => 'array',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'last_generated_on' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    protected $attributes = ['interval' => 1, 'is_active' => true];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'recurrence_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The dates this recurrence should produce work on, within a window.
     *
     * Computed rather than stored so that changing a schedule takes effect
     * immediately instead of leaving a queue of pre-generated jobs behind.
     *
     * @return list<CarbonImmutable>
     */
    public function occurrencesBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];
        $cursor = $this->starts_on->greaterThan($from) ? $this->starts_on : $from;
        $limit = $this->ends_on !== null && $this->ends_on->lessThan($to) ? $this->ends_on : $to;

        // A generous guard: even a daily recurrence over a long window cannot
        // spin forever.
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($limit) && $guard++ < 1000) {
            if ($this->matches($cursor)) {
                $dates[] = $cursor;
            }

            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    private function matches(CarbonImmutable $date): bool
    {
        $interval = max(1, (int) $this->interval);

        return match ($this->frequency) {
            'daily' => (int) $this->starts_on->diffInDays($date) % $interval === 0,

            'weekly' => $this->matchesDayOfWeek($date)
                && (int) floor($this->starts_on->diffInDays($date) / 7) % $interval === 0,

            'monthly' => (int) $date->day === (int) ($this->day_of_month ?? $this->starts_on->day)
                && (int) $this->starts_on->diffInMonths($date) % $interval === 0,

            'quarterly' => (int) $date->day === (int) ($this->day_of_month ?? $this->starts_on->day)
                && (int) $this->starts_on->diffInMonths($date) % 3 === 0,

            'yearly' => (int) $date->month === (int) $this->starts_on->month
                && (int) $date->day === (int) $this->starts_on->day,

            default => false,
        };
    }

    private function matchesDayOfWeek(CarbonImmutable $date): bool
    {
        $days = $this->days_of_week;

        if ($days === null || $days === []) {
            return (int) $date->dayOfWeek === (int) $this->starts_on->dayOfWeek;
        }

        return in_array((int) $date->dayOfWeek, array_map('intval', $days), true);
    }
}
