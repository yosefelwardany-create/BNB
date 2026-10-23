<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * Nightly rates and stay restrictions for a date range, in minor units of the
 * listing's currency.
 *
 * Restrictions appear here as well as on {@see ChannelAvailabilityUpdate}
 * because channels partition them differently and neither partition is wrong.
 * Hostex takes a count and its restrictions on one calendar call; Channex
 * splits strictly — availability is a number per room type, while the price
 * and every rule about staying a night belong to a rate plan and travel
 * together as "rates & restrictions".
 *
 * An adapter therefore reads whichever set its own API expects, and the two
 * carry the same truth rather than one of them being authoritative.
 */
final class ChannelRateUpdate
{
    /**
     * @param  array<string, int>  $nightlyRates  Y-m-d => minor units
     * @param  array<string, int>  $minimumStay
     * @param  array<string, int>  $maximumStay
     * @param  array<string, bool>  $closedToArrival
     * @param  array<string, bool>  $closedToDeparture
     * @param  array<string, bool>  $stopSell  Y-m-d => closed to sale by hand
     */
    public function __construct(
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly string $currency,
        public readonly array $nightlyRates,
        public readonly array $minimumStay = [],
        public readonly array $maximumStay = [],
        public readonly ?string $ratePlanReference = null,
        public readonly array $closedToArrival = [],
        public readonly array $closedToDeparture = [],
        public readonly array $stopSell = [],
    ) {}
}
