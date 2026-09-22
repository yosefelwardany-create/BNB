<?php

declare(strict_types=1);

namespace App\Domain\Availability\DataObjects;

use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\Unit;
use Carbon\CarbonImmutable;

/**
 * Everything the availability engine needs to answer one question.
 *
 * `checkOut` is exclusive. `ignoreReservationId` is what makes modifying an
 * existing booking work: the stay being changed must not count itself as a
 * conflict.
 */
final class AvailabilityRequest
{
    public function __construct(
        public readonly Property $property,
        public readonly CarbonImmutable $checkIn,
        public readonly CarbonImmutable $checkOut,
        public readonly ?Listing $listing = null,
        public readonly ?Unit $unit = null,
        public readonly ?string $unitTypeId = null,
        public readonly ?int $guests = null,
        /** How many units of inventory are wanted (group bookings). */
        public readonly int $quantity = 1,
        /** Exclude this reservation from conflict checks when modifying it. */
        public readonly ?string $ignoreReservationId = null,
        /** Staff with the override permission may book past stay restrictions. */
        public readonly bool $ignoreRestrictions = false,
        public readonly bool $ignorePropertyStatus = false,
    ) {}

    public function nights(): int
    {
        return (int) $this->checkIn->startOfDay()->diffInDays($this->checkOut->startOfDay());
    }

    /**
     * A copy with restrictions waived — used when an authorised agent
     * deliberately books below the minimum stay.
     */
    public function withoutRestrictions(): self
    {
        return new self(
            property: $this->property,
            checkIn: $this->checkIn,
            checkOut: $this->checkOut,
            listing: $this->listing,
            unit: $this->unit,
            unitTypeId: $this->unitTypeId,
            guests: $this->guests,
            quantity: $this->quantity,
            ignoreReservationId: $this->ignoreReservationId,
            ignoreRestrictions: true,
            ignorePropertyStatus: $this->ignorePropertyStatus,
        );
    }
}
