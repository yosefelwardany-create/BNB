<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A guest-facing discount, either automatic or unlocked by a code.
 *
 * Usage limits are enforced when the promotion is applied, inside the booking
 * transaction, so a code limited to 100 uses cannot be redeemed 150 times by
 * concurrent bookings.
 */
class Promotion extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const PERCENT = 'percent';

    public const FIXED = 'fixed';

    public const FREE_NIGHTS = 'free_nights';

    protected $fillable = [
        'organization_id', 'name', 'code', 'description',
        'discount_type', 'discount_value', 'currency',
        'bookable_from', 'bookable_to', 'stay_from', 'stay_to',
        'minimum_nights', 'minimum_spend', 'maximum_uses', 'maximum_uses_per_guest',
        'property_ids', 'channels', 'combinable', 'applies_to_fees',
        'is_active', 'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:4',
            'bookable_from' => 'immutable_date',
            'bookable_to' => 'immutable_date',
            'stay_from' => 'immutable_date',
            'stay_to' => 'immutable_date',
            'property_ids' => 'array',
            'channels' => 'array',
            'combinable' => 'boolean',
            'applies_to_fees' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected $attributes = [
        'combinable' => false,
        'applies_to_fees' => false,
        'is_active' => true,
        'times_used' => 0,
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Promotions applied without the guest entering anything. */
    public function scopeAutomatic(Builder $query): Builder
    {
        return $query->whereNull('code');
    }

    /**
     * Whether the promotion may be used for a particular stay.
     *
     * @return list<string> reasons it may not; empty means it may
     */
    public function eligibilityErrors(
        CarbonImmutable $bookingDate,
        CarbonImmutable $checkIn,
        CarbonImmutable $checkOut,
        string $propertyId,
        string $channel,
        Money $accommodationTotal,
    ): array {
        $errors = [];

        if (! $this->is_active) {
            $errors[] = 'This promotion is no longer active.';
        }

        if ($this->bookable_from !== null && $bookingDate->lt($this->bookable_from)) {
            $errors[] = sprintf('This promotion starts on %s.', $this->bookable_from->format('j M Y'));
        }

        if ($this->bookable_to !== null && $bookingDate->gt($this->bookable_to)) {
            $errors[] = 'This promotion has expired.';
        }

        if ($this->stay_from !== null && $checkIn->lt($this->stay_from)) {
            $errors[] = sprintf('This promotion applies to stays from %s.', $this->stay_from->format('j M Y'));
        }

        if ($this->stay_to !== null && $checkOut->gt($this->stay_to->addDay())) {
            $errors[] = sprintf('This promotion applies to stays until %s.', $this->stay_to->format('j M Y'));
        }

        $nights = (int) $checkIn->diffInDays($checkOut);

        if ($this->minimum_nights !== null && $nights < $this->minimum_nights) {
            $errors[] = sprintf('This promotion requires a stay of at least %d nights.', $this->minimum_nights);
        }

        if ($this->minimum_spend !== null && $accommodationTotal->minorUnits < (int) $this->minimum_spend) {
            $errors[] = 'The booking total is below this promotion\'s minimum spend.';
        }

        $properties = $this->property_ids;

        if ($properties !== null && $properties !== [] && ! in_array($propertyId, $properties, true)) {
            $errors[] = 'This promotion does not apply to this property.';
        }

        $channels = $this->channels;

        if ($channels !== null && $channels !== [] && ! in_array($channel, $channels, true)) {
            $errors[] = 'This promotion does not apply to bookings from this channel.';
        }

        if ($this->maximum_uses !== null && (int) $this->times_used >= (int) $this->maximum_uses) {
            $errors[] = 'This promotion has reached its usage limit.';
        }

        return $errors;
    }

    /**
     * The discount for a given accommodation subtotal.
     *
     * Free-night promotions are resolved by the pricing engine, which knows
     * the individual nightly rates; this returns zero for them so the caller
     * cannot accidentally double-count.
     */
    public function discountFor(Money $accommodation, Money $fees): Money
    {
        $base = $this->applies_to_fees ? $accommodation->add($fees) : $accommodation;

        return match ($this->discount_type) {
            self::PERCENT => $base->percentage((float) $this->discount_value),
            self::FIXED => Money::of((int) $this->discount_value, $base->currency),
            default => Money::zero($base->currency),
        };
    }
}
