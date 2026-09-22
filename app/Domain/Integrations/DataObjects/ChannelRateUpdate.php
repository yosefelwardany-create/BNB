<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * Nightly rates for a date range, in minor units of the listing's currency.
 */
final class ChannelRateUpdate
{
    /**
     * @param  array<string, int>  $nightlyRates  Y-m-d => minor units
     * @param  array<string, int>  $minimumStay
     * @param  array<string, int>  $maximumStay
     */
    public function __construct(
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly string $currency,
        public readonly array $nightlyRates,
        public readonly array $minimumStay = [],
        public readonly array $maximumStay = [],
        public readonly ?string $ratePlanReference = null,
    ) {}
}
