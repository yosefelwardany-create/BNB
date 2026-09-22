<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DataObjects;

use App\Domain\Listings\Models\Listing;
use App\Domain\Pricing\Models\RatePlan;
use Carbon\CarbonImmutable;

/**
 * The inputs to a price calculation.
 *
 * Gathered into one object so pricing is a pure function of its inputs: given
 * the same context and the same rules, the engine must always produce the same
 * quote. `bookingDate` is explicit rather than read from the clock precisely
 * so that a quote can be reproduced later.
 */
final class PricingContext
{
    public function __construct(
        public readonly Listing $listing,
        public readonly CarbonImmutable $checkIn,
        public readonly CarbonImmutable $checkOut,
        public readonly int $adults = 1,
        public readonly int $children = 0,
        public readonly int $infants = 0,
        public readonly int $pets = 0,
        public readonly string $channel = 'direct',
        public readonly ?RatePlan $ratePlan = null,
        public readonly ?string $promotionCode = null,
        /** When the booking is being made. Explicit so quotes are reproducible. */
        public readonly ?CarbonImmutable $bookingDate = null,
        public readonly ?string $unitId = null,
        /** Skip optional fees the guest has not chosen. */
        public readonly bool $includeOptionalFees = false,
    ) {}

    public function nights(): int
    {
        return (int) $this->checkIn->startOfDay()->diffInDays($this->checkOut->startOfDay());
    }

    /** Occupancy for pricing: infants are not counted. */
    public function guests(): int
    {
        return $this->adults + $this->children;
    }

    public function bookedOn(): CarbonImmutable
    {
        return $this->bookingDate ?? CarbonImmutable::now(
            $this->listing->property?->timezone ?? 'UTC'
        );
    }

    /** Days between the booking being made and arrival. */
    public function leadTimeDays(): int
    {
        return (int) floor(
            $this->bookedOn()->startOfDay()->diffInDays($this->checkIn->startOfDay(), false)
        );
    }

    /**
     * @return list<string> the stay's nights as Y-m-d
     */
    public function nightDates(): array
    {
        $dates = [];
        $cursor = $this->checkIn->startOfDay();

        while ($cursor < $this->checkOut->startOfDay()) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates;
    }
}
