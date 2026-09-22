<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DataObjects;

use App\Support\Money\Money;

/**
 * One night's rate, with the audit trail of how it was reached.
 *
 * `steps` records every rule that touched the rate in the order it ran, so a
 * question like "why is the 14th more expensive than the 13th?" is answered
 * from data rather than from someone re-reading the rules.
 */
final class NightPrice
{
    /**
     * @param  list<PricingStep>  $steps
     */
    public function __construct(
        public readonly string $date,
        public readonly Money $rate,
        public readonly Money $baseRate,
        public readonly array $steps = [],
        public readonly ?string $unitId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'rate' => $this->rate->jsonSerialize(),
            'base_rate' => $this->baseRate->jsonSerialize(),
            'unit_id' => $this->unitId,
            'steps' => array_map(fn (PricingStep $s): array => $s->toArray(), $this->steps),
        ];
    }

    /**
     * A one-line human explanation of this night's price.
     */
    public function explanation(): string
    {
        if ($this->steps === []) {
            return sprintf('%s: base rate %s', $this->date, $this->baseRate->toDecimal());
        }

        $parts = array_map(fn (PricingStep $s): string => $s->describe(), $this->steps);

        return sprintf('%s: %s → %s', $this->date, implode(', then ', $parts), $this->rate->toDecimal());
    }
}
