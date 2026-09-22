<?php

declare(strict_types=1);

namespace App\Domain\Automation\Models;

use App\Domain\Users\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An automation rule: Event → Conditions → Actions, stored as data.
 *
 * Two trigger shapes cover everything the product needs:
 *
 *  - `event`: react to something that happened (reservation.confirmed).
 *  - `schedule`: act relative to a date on a reservation ("three days before
 *    check-in, at 10am"). The offset is evaluated in the *property's* local
 *    time, which is the only way a portfolio spanning timezones sends arrival
 *    instructions at a sensible hour for each guest.
 */
class AutomationRule extends BaseModel
{
    use Auditable, BelongsToOrganization, HasFactory;

    public const TRIGGER_EVENT = 'event';

    public const TRIGGER_SCHEDULE = 'schedule';

    public const ANCHOR_CHECK_IN = 'check_in';

    public const ANCHOR_CHECK_OUT = 'check_out';

    public const ANCHOR_BOOKED_AT = 'booked_at';

    protected $fillable = [
        'organization_id', 'name', 'description',
        'trigger_type', 'trigger_event',
        'trigger_anchor', 'trigger_offset_minutes', 'trigger_time_of_day',
        'conditions', 'actions', 'delay_minutes',
        'property_ids', 'channels', 'is_active', 'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'actions' => 'array',
            'property_ids' => 'array',
            'channels' => 'array',
            'is_active' => 'boolean',
            'last_run_at' => 'immutable_datetime',
        ];
    }

    protected $attributes = [
        'trigger_type' => self::TRIGGER_EVENT,
        'delay_minutes' => 0,
        'is_active' => true,
        'times_run' => 0,
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class)->latest();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForEvent(Builder $query, string $eventName): Builder
    {
        return $query->active()
            ->where('trigger_type', self::TRIGGER_EVENT)
            ->where('trigger_event', $eventName);
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->active()->where('trigger_type', self::TRIGGER_SCHEDULE);
    }

    public function appliesToProperty(?string $propertyId): bool
    {
        $properties = $this->property_ids;

        if ($properties === null || $properties === [] || $propertyId === null) {
            return true;
        }

        return in_array($propertyId, $properties, true);
    }

    public function appliesToChannel(?string $channel): bool
    {
        $channels = $this->channels;

        if ($channels === null || $channels === [] || $channel === null) {
            return true;
        }

        return in_array($channel, $channels, true);
    }

    /**
     * A readable description of when this rule fires, shown in the rule list
     * so an operator does not have to decode an offset in minutes.
     */
    public function triggerDescription(): string
    {
        if ($this->trigger_type === self::TRIGGER_EVENT) {
            return sprintf('When %s', str_replace(['.', '_'], [' ', ' '], (string) $this->trigger_event));
        }

        $minutes = (int) $this->trigger_offset_minutes;
        $anchor = str_replace('_', '-', (string) $this->trigger_anchor);

        if ($minutes === 0) {
            return sprintf('At %s', $anchor);
        }

        $absolute = abs($minutes);
        $when = $minutes < 0 ? 'before' : 'after';

        $amount = match (true) {
            $absolute % 1440 === 0 => sprintf('%d day(s)', intdiv($absolute, 1440)),
            $absolute % 60 === 0 => sprintf('%d hour(s)', intdiv($absolute, 60)),
            default => sprintf('%d minute(s)', $absolute),
        };

        $time = $this->trigger_time_of_day !== null
            ? sprintf(' at %s local time', substr((string) $this->trigger_time_of_day, 0, 5))
            : '';

        return sprintf('%s %s %s%s', $amount, $when, $anchor, $time);
    }
}
