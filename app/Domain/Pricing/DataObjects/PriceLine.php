<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DataObjects;

use App\Support\Money\Money;

/**
 * A fee, discount or tax line, with the calculation that produced it.
 */
final class PriceLine
{
    /**
     * @param  array<string, mixed>  $trace  The inputs and arithmetic behind `amount`.
     */
    public function __construct(
        public readonly string $kind,        // fee|discount|tax|upsell
        public readonly string $code,
        public readonly string $label,
        public readonly Money $amount,
        public readonly int $quantity = 1,
        public readonly ?Money $unitAmount = null,
        public readonly bool $isTaxable = false,
        public readonly bool $isRefundable = true,
        public readonly ?string $ruleId = null,
        public readonly array $trace = [],
        public readonly ?string $description = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'code' => $this->code,
            'label' => $this->label,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_amount' => $this->unitAmount?->jsonSerialize(),
            'amount' => $this->amount->jsonSerialize(),
            'is_taxable' => $this->isTaxable,
            'is_refundable' => $this->isRefundable,
            'rule_id' => $this->ruleId,
            'trace' => $this->trace,
        ];
    }
}
