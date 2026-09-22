<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DataObjects;

use App\Support\Money\Money;

/**
 * One rule's effect on a night's rate.
 */
final class PricingStep
{
    public function __construct(
        public readonly string $source,      // 'base', 'calendar_override', 'rate_plan', 'rule'
        public readonly string $label,
        public readonly Money $before,
        public readonly Money $after,
        public readonly ?string $ruleId = null,
        public readonly ?string $adjustmentType = null,
        public readonly int|float|null $adjustmentValue = null,
    ) {}

    public function delta(): Money
    {
        return $this->after->subtract($this->before);
    }

    public function describe(): string
    {
        $delta = $this->delta();

        if ($delta->isZero()) {
            return sprintf('%s (no change)', $this->label);
        }

        return sprintf(
            '%s %s%s',
            $this->label,
            $delta->isPositive() ? '+' : '',
            $delta->toDecimal(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'label' => $this->label,
            'rule_id' => $this->ruleId,
            'adjustment_type' => $this->adjustmentType,
            'adjustment_value' => $this->adjustmentValue,
            'before' => $this->before->minorUnits,
            'after' => $this->after->minorUnits,
            'delta' => $this->delta()->minorUnits,
            'description' => $this->describe(),
        ];
    }
}
