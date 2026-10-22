<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * Availability for a contiguous date range, as pushed to a channel.
 *
 * `days` maps Y-m-d to the number of units still sellable. Sending explicit
 * dates rather than a delta means a dropped message cannot leave a channel
 * believing a sold night is still for sale.
 *
 * `stopSell` is separate from a zero in `days` on purpose. A zero says the
 * inventory is gone; a stop-sell says the night is closed to sale whatever
 * the count. A hand-closed night has always been sent as zero units, which
 * most channels read correctly, but it is a weaker statement than the one the
 * operator made — and a channel that models the two separately expects the
 * flag. Only adapters declaring CAPABILITY_STOP_SELL act on it.
 */
final class ChannelAvailabilityUpdate
{
    /**
     * @param  array<string, int>  $days  Y-m-d => units still sellable
     * @param  array<string, int>  $minimumStay  Y-m-d => nights
     * @param  array<string, bool>  $closedToArrival
     * @param  array<string, bool>  $closedToDeparture
     * @param  array<string, bool>  $stopSell  Y-m-d => closed to sale by hand
     */
    public function __construct(
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly array $days,
        public readonly array $minimumStay = [],
        public readonly array $closedToArrival = [],
        public readonly array $closedToDeparture = [],
        public readonly array $stopSell = [],
    ) {}
}
