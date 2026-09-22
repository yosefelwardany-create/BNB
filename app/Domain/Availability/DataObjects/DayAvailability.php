<?php

declare(strict_types=1);

namespace App\Domain\Availability\DataObjects;

/**
 * One cell of the multi-calendar.
 *
 * Carries both the counts (so a building shows "3 of 12 free" rather than a
 * binary) and the restrictions, because an operator looking at a calendar
 * needs to see why a night is not selling as well as whether it is.
 */
final class DayAvailability
{
    /**
     * @param  list<string>  $reservationIds
     * @param  list<string>  $blockIds
     */
    public function __construct(
        public readonly string $date,
        public readonly bool $isAvailable,
        public readonly int $totalUnits,
        public readonly int $soldUnits,
        public readonly int $blockedUnits,
        public readonly int $remainingUnits,
        public readonly bool $isManuallyBlocked = false,
        public readonly ?int $minimumNights = null,
        public readonly ?int $maximumNights = null,
        public readonly bool $closedToArrival = false,
        public readonly bool $closedToDeparture = false,
        public readonly ?int $rateOverride = null,
        public readonly ?string $note = null,
        public readonly array $reservationIds = [],
        public readonly array $blockIds = [],
    ) {}

    /**
     * Occupancy for this date as a percentage of sellable inventory.
     */
    public function occupancyRate(): float
    {
        if ($this->totalUnits < 1) {
            return 0.0;
        }

        return round(($this->soldUnits / $this->totalUnits) * 100, 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'available' => $this->isAvailable,
            'total_units' => $this->totalUnits,
            'sold_units' => $this->soldUnits,
            'blocked_units' => $this->blockedUnits,
            'remaining_units' => $this->remainingUnits,
            'occupancy_rate' => $this->occupancyRate(),
            'manually_blocked' => $this->isManuallyBlocked,
            'minimum_nights' => $this->minimumNights,
            'maximum_nights' => $this->maximumNights,
            'closed_to_arrival' => $this->closedToArrival,
            'closed_to_departure' => $this->closedToDeparture,
            'rate_override' => $this->rateOverride,
            'note' => $this->note,
            'reservation_ids' => $this->reservationIds,
            'block_ids' => $this->blockIds,
        ];
    }
}
