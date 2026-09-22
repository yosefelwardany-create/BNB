<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\DataObjects\PriceLine;
use App\Domain\Pricing\DataObjects\PricingContext;
use App\Domain\Pricing\Models\TaxRule;
use App\Support\Money\Money;

/**
 * Computes lodging taxes.
 *
 * Three things here are easy to get wrong and expensive when they are:
 *
 *  - **Order and compounding.** Taxes apply in priority order, and a tax marked
 *    `compounds_on_taxes` charges on a base that already includes the taxes
 *    applied before it. Getting this backwards under- or over-collects on every
 *    booking in that jurisdiction.
 *  - **Night caps.** Many city taxes stop after a fixed number of nights. A
 *    28-night stay taxed on all 28 nights instead of the first 7 overcharges
 *    the guest fourfold.
 *  - **Channel-collected taxes.** Where a channel collects and remits a tax
 *    itself, the manager must not also record it as owed. Those taxes are
 *    reported as a notice and excluded from the amount charged.
 *
 * Every line carries its full calculation so a remittance figure can be
 * defended.
 */
class TaxCalculator
{
    /**
     * @param  list<PriceLine>  $feeLines
     * @return array{0: list<PriceLine>, 1: list<string>} lines and notices
     */
    public function calculate(PricingContext $context, Money $accommodation, array $feeLines): array
    {
        $property = $context->listing->property;
        $currency = $context->listing->currency;

        if ($property === null) {
            return [[], []];
        }

        $rules = TaxRule::query()
            ->forProperty($property)
            ->get()
            ->filter(fn (TaxRule $rule): bool => $rule->isInForce($context->checkIn)
                && $rule->appliesToChannel($context->channel));

        $lines = [];
        $notices = [];

        // Accumulates as compounding taxes are applied, so a later tax that
        // compounds sees the ones before it.
        $taxesSoFar = Money::zero($currency);

        foreach ($rules as $rule) {
            // A tax the channel collects and remits is not the manager's to
            // charge — but the operator still needs to know it exists.
            if ($rule->isCollectedBy($context->channel)) {
                $notices[] = sprintf(
                    '%s is collected and remitted by %s for this booking.',
                    $rule->name,
                    $context->channel,
                );

                continue;
            }

            $base = $this->baseFor($rule, $accommodation, $feeLines, $taxesSoFar, $currency);

            [$amount, $trace] = $this->amountFor($rule, $context, $base, $currency);

            if ($amount->isZero()) {
                continue;
            }

            // An overall cap, where the jurisdiction sets one.
            if ($rule->maximum_amount !== null) {
                $cap = Money::of((int) $rule->maximum_amount, $currency);

                if ($amount->greaterThan($cap)) {
                    $trace['capped_from'] = $amount->minorUnits;
                    $amount = $cap;
                }
            }

            $lines[] = new PriceLine(
                kind: 'tax',
                code: $rule->code,
                label: $rule->name,
                amount: $amount,
                quantity: (int) ($trace['taxable_units'] ?? 1),
                isTaxable: false,
                // Whether tax comes back depends on the cancellation policy,
                // which decides at cancellation time; the line stays refundable
                // here so the policy has something to work with.
                isRefundable: true,
                ruleId: $rule->getKey(),
                trace: $trace + [
                    'base' => $base->minorUnits,
                    'calculation' => $rule->calculation,
                    'compounds_on_taxes' => $rule->compounds_on_taxes,
                    'priority' => (int) $rule->priority,
                ],
                description: $rule->description,
            );

            $taxesSoFar = $taxesSoFar->add($amount);
        }

        return [$lines, $notices];
    }

    /**
     * What this tax is charged on.
     *
     * @param  list<PriceLine>  $feeLines
     */
    private function baseFor(
        TaxRule $rule,
        Money $accommodation,
        array $feeLines,
        Money $taxesSoFar,
        string $currency,
    ): Money {
        $base = Money::zero($currency);

        if ($rule->applies_to_accommodation) {
            $base = $base->add($accommodation);
        }

        if ($rule->applies_to_fees) {
            $codes = $rule->applies_to_fee_codes;

            foreach ($feeLines as $fee) {
                // A tax may apply to all fees, or only to named ones — a
                // cleaning fee is taxable in many places where a damage
                // deposit is not.
                if ($codes !== null && $codes !== [] && ! in_array($fee->code, $codes, true)) {
                    continue;
                }

                if (! $fee->isTaxable) {
                    continue;
                }

                $base = $base->add($fee->amount);
            }
        }

        if ($rule->compounds_on_taxes) {
            $base = $base->add($taxesSoFar);
        }

        return $base;
    }

    /**
     * The amount due, and the arithmetic behind it.
     *
     * @return array{0: Money, 1: array<string, mixed>}
     */
    private function amountFor(TaxRule $rule, PricingContext $context, Money $base, string $currency): array
    {
        $nights = $context->nights();
        $guests = $context->guests();

        // Age exemptions: where a tax exempts under-Ns, children are treated
        // as falling under that threshold. The exact ages of individual guests
        // are captured on the reservation's guest list, and the reservation
        // service re-runs this with real ages when it has them.
        if ($rule->exempt_guest_age_under !== null) {
            $guests = max(0, $guests - $context->children);
        }

        // Night caps: the tax stops after N nights.
        $taxableNights = $nights;

        if ($rule->maximum_nights !== null) {
            $taxableNights = min($nights, (int) $rule->maximum_nights);
        }

        // Some jurisdictions exempt the whole stay once it passes a length
        // (a long stay becomes a tenancy rather than a lodging).
        if ($rule->exempt_after_nights !== null && $nights > (int) $rule->exempt_after_nights) {
            return [
                Money::zero($currency),
                [
                    'exempt' => true,
                    'reason' => sprintf(
                        'Stays longer than %d nights are exempt from %s.',
                        (int) $rule->exempt_after_nights,
                        $rule->name,
                    ),
                ],
            ];
        }

        $fixed = Money::of((int) ($rule->amount ?? 0), $rule->currency ?? $currency);

        return match ($rule->calculation) {
            TaxRule::PERCENT => [
                $base->percentage((float) $rule->rate),
                ['rate_percent' => (float) $rule->rate, 'taxable_units' => 1],
            ],

            TaxRule::FIXED_PER_STAY => [
                $fixed,
                ['taxable_units' => 1],
            ],

            TaxRule::FIXED_PER_NIGHT => [
                $fixed->multiply($taxableNights),
                ['taxable_units' => $taxableNights, 'nights' => $nights, 'capped_nights' => $taxableNights],
            ],

            TaxRule::FIXED_PER_GUEST => [
                $fixed->multiply($guests),
                ['taxable_units' => $guests, 'guests' => $guests],
            ],

            TaxRule::FIXED_PER_GUEST_PER_NIGHT => [
                $fixed->multiply($guests * $taxableNights),
                [
                    'taxable_units' => $guests * $taxableNights,
                    'guests' => $guests,
                    'nights' => $nights,
                    'capped_nights' => $taxableNights,
                ],
            ],

            default => [Money::zero($currency), ['error' => 'unknown_calculation']],
        };
    }
}
