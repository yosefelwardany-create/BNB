<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * Availability for a contiguous date range, as pushed to a channel.
 *
 * `days` maps Y-m-d to whether the date is bookable. Sending explicit dates
 * rather than a delta means a dropped message cannot leave a channel believing
 * a sold night is still for sale.
 */
final class ChannelAvailabilityUpdate
{
    /**
     * @param  array<string, bool>  $days  Y-m-d => available
     * @param  array<string, int>  $minimumStay  Y-m-d => nights
     * @param  array<string, bool>  $closedToArrival
     * @param  array<string, bool>  $closedToDeparture
     */
    public function __construct(
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly array $days,
        public readonly array $minimumStay = [],
        public readonly array $closedToArrival = [],
        public readonly array $closedToDeparture = [],
    ) {}
}
