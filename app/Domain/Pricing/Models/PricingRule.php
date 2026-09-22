<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\Property;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One deterministic adjustment to a nightly rate.
 *
 * Rules run in ascending `priority`, each operating on the result of the last,
 * and each records what it did. That ordering is the whole contract: pricing
 * must be reproducible, so nothing here depends on wall-clock time, random
 * numbers or the order rows happen to come back from the database.
 */
class PricingRule extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const KIND_BASE = 'base_rate';

    public const KIND_SEASONAL = 'seasonal';

    public const KIND_DAY_OF_WEEK = 'day_of_week';

    public const KIND_LENGTH_OF_STAY = 'length_of_stay';

    public const KIND_OCCUPANCY = 'occupancy';

    public const KIND_EARLY_BOOKING = 'early_booking';

    public const KIND_LAST_MINUTE = 'last_minute';

    public const KIND_GAP_NIGHT = 'gap_night';

    public const KIND_ORPHAN_NIGHT = 'orphan_night';

    public const KIND_CUSTOM = 'custom';

    public const ADJUST_SET = 'set';

    public const ADJUST_INCREASE_PERCENT = 'increase_percent';

    public const ADJUST_DECREASE_PERCENT = 'decrease_percent';

    public const ADJUST_INCREASE_FIXED = 'increase_fixed';

    public const ADJUST_DECREASE_FIXED = 'decrease_fixed';

    protected $fillable = [
        'organization_id', 'name', 'description', 'kind',
        'property_id', 'listing_id', 'unit_type_id', 'portfolio_id', 'rate_plan_id',
        'channels', 'effective_from', 'effective_to', 'stay_from', 'stay_to',
        'days_of_week', 'conditions', 'adjustment_type', 'adjustment_value',
        'floor_rate', 'ceiling_rate', 'priority', 'is_exclusive', 'is_active',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'days_of_week' => 'array',
            'conditions' => 'array',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'stay_from' => 'immutable_date',
            'stay_to' => 'immutable_date',
            'is_exclusive' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected $attributes = [
        'priority' => 100,
        'is_exclusive' => false,
        'is_active' => true,
        'kind' => self::KIND_CUSTOM,
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Rules that could apply to a listing, in the order they must run.
     *
     * Scope columns are matched as "this rule's scope is null (applies
     * everywhere) or equals the target", so a portfolio-wide rule and a
     * property-specific one both come back and are ordered by priority.
     */
    public function scopeApplicableTo(Builder $query, Listing $listing): Builder
    {
        $property = $listing->property;

        return $query->active()
            ->where(fn (Builder $q) => $q->whereNull('property_id')->orWhere('property_id', $listing->property_id))
            ->where(fn (Builder $q) => $q->whereNull('listing_id')->orWhere('listing_id', $listing->getKey()))
            ->where(fn (Builder $q) => $q->whereNull('unit_type_id')->orWhere('unit_type_id', $listing->unit_type_id))
            ->where(fn (Builder $q) => $q->whereNull('portfolio_id')->orWhere('portfolio_id', $property?->portfolio_id))
            ->orderBy('priority')
            // A stable tiebreaker so two rules of equal priority always apply
            // in the same order; otherwise a price would not be reproducible.
            ->orderBy('id');
    }

    public function adjustmentAmount(string $currency): Money
    {
        return Money::of((int) $this->adjustment_value, $currency);
    }

    /**
     * Whether the rule is in force on the day the booking is being made.
     */
    public function isInForce(\DateTimeInterface $on): bool
    {
        $date = CarbonImmutable::parse($on)->startOfDay();

        if ($this->effective_from !== null && $date->lt($this->effective_from)) {
            return false;
        }

        return ! ($this->effective_to !== null && $date->gt($this->effective_to));
    }
}
