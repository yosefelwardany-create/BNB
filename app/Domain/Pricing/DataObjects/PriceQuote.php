<?php

declare(strict_types=1);

namespace App\Domain\Pricing\DataObjects;

use App\Support\Money\Money;

/**
 * A fully itemised price.
 *
 * Every figure a guest, an owner or an auditor could question is present with
 * the reasoning that produced it: each night carries the rules that shaped its
 * rate, and each fee, discount and tax carries its calculation. Nothing in the
 * product ever quotes an amount it cannot explain.
 */
final class PriceQuote
{
    /**
     * @param  list<NightPrice>  $nights
     * @param  list<PriceLine>  $fees
     * @param  list<PriceLine>  $discounts
     * @param  list<PriceLine>  $taxes
     * @param  list<string>  $notices  Things the caller should surface (e.g. a tax the channel collects).
     */
    public function __construct(
        public readonly string $currency,
        public readonly array $nights,
        public readonly array $fees = [],
        public readonly array $discounts = [],
        public readonly array $taxes = [],
        public readonly ?string $ratePlanId = null,
        public readonly ?string $ratePlanName = null,
        public readonly ?string $promotionId = null,
        public readonly array $notices = [],
    ) {}

    public function accommodationTotal(): Money
    {
        return Money::sum(
            array_map(fn (NightPrice $n): Money => $n->rate, $this->nights),
            $this->currency,
        );
    }

    public function feesTotal(): Money
    {
        return Money::sum(
            array_map(fn (PriceLine $l): Money => $l->amount, $this->fees),
            $this->currency,
        );
    }

    /**
     * Discounts as a positive magnitude.
     */
    public function discountsTotal(): Money
    {
        return Money::sum(
            array_map(fn (PriceLine $l): Money => $l->amount->absolute(), $this->discounts),
            $this->currency,
        );
    }

    public function taxesTotal(): Money
    {
        return Money::sum(
            array_map(fn (PriceLine $l): Money => $l->amount, $this->taxes),
            $this->currency,
        );
    }

    /**
     * The subtotal taxes are computed on: accommodation plus taxable fees,
     * less discounts.
     */
    public function taxableSubtotal(): Money
    {
        $taxableFees = Money::sum(
            array_map(
                fn (PriceLine $l): Money => $l->amount,
                array_filter($this->fees, fn (PriceLine $l): bool => $l->isTaxable),
            ),
            $this->currency,
        );

        return $this->accommodationTotal()
            ->add($taxableFees)
            ->subtract($this->discountsTotal());
    }

    public function grandTotal(): Money
    {
        return $this->accommodationTotal()
            ->add($this->feesTotal())
            ->add($this->taxesTotal())
            ->subtract($this->discountsTotal());
    }

    public function nightCount(): int
    {
        return count($this->nights);
    }

    /**
     * Average nightly rate: accommodation only. This is the ADR the product
     * reports everywhere.
     */
    public function averageNightlyRate(): Money
    {
        if ($this->nights === []) {
            return Money::zero($this->currency);
        }

        return Money::of(
            (int) round($this->accommodationTotal()->minorUnits / count($this->nights)),
            $this->currency,
        );
    }

    /**
     * The whole quote as an array, suitable for an API response, a stored
     * quote record or a guest-facing breakdown.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency,
            'nights' => array_map(fn (NightPrice $n): array => $n->toArray(), $this->nights),
            'fees' => array_map(fn (PriceLine $l): array => $l->toArray(), $this->fees),
            'discounts' => array_map(fn (PriceLine $l): array => $l->toArray(), $this->discounts),
            'taxes' => array_map(fn (PriceLine $l): array => $l->toArray(), $this->taxes),
            'totals' => [
                'accommodation' => $this->accommodationTotal()->jsonSerialize(),
                'fees' => $this->feesTotal()->jsonSerialize(),
                'discounts' => $this->discountsTotal()->jsonSerialize(),
                'taxes' => $this->taxesTotal()->jsonSerialize(),
                'grand_total' => $this->grandTotal()->jsonSerialize(),
                'average_nightly_rate' => $this->averageNightlyRate()->jsonSerialize(),
            ],
            'nights_count' => $this->nightCount(),
            'rate_plan_id' => $this->ratePlanId,
            'rate_plan_name' => $this->ratePlanName,
            'promotion_id' => $this->promotionId,
            'notices' => $this->notices,
        ];
    }
}
