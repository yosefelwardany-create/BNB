<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\DataObjects\PriceLine;
use App\Domain\Pricing\DataObjects\PricingContext;
use App\Domain\Pricing\Models\FeeRule;
use App\Support\Money\Money;

/**
 * Turns fee rules into itemised lines.
 *
 * Each line records the quantity, the unit amount and the arithmetic, because
 * "cleaning fee 85.00" is not an answer to "why am I paying 85?" when the fee
 * is actually 40 plus 15 a night for three nights.
 */
class FeeCalculator
{
    /**
     * @return list<PriceLine>
     */
    public function calculate(PricingContext $context, Money $accommodation): array
    {
        $currency = $context->listing->currency;
        $nights = $context->nights();
        $guests = $context->guests();

        $rules = FeeRule::query()
            ->forListing($context->listing)
            ->get()
            ->filter(fn (FeeRule $rule): bool => $rule->isInForce($context->checkIn)
                && $rule->appliesToChannel($context->channel)
                && ($context->includeOptionalFees || ! $rule->is_optional));

        $lines = [];

        // The listing's own cleaning fee, when no rule supersedes it. Most
        // properties set a single cleaning fee on the listing and never touch
        // fee rules at all, and that has to work.
        $hasCleaningRule = $rules->contains(fn (FeeRule $r): bool => $r->kind === 'cleaning');
        $listingCleaning = $context->listing->cleaningFee();

        if (! $hasCleaningRule && $listingCleaning->isPositive()) {
            $lines[] = new PriceLine(
                kind: 'fee',
                code: 'cleaning',
                label: 'Cleaning fee',
                amount: $listingCleaning,
                quantity: 1,
                unitAmount: $listingCleaning,
                isTaxable: true,
                isRefundable: true,
                trace: ['source' => 'listing', 'basis' => FeeRule::PER_STAY],
            );
        }

        // Extra-guest fee from the listing, likewise.
        $hasExtraGuestRule = $rules->contains(fn (FeeRule $r): bool => $r->kind === 'extra_guest');
        $threshold = $context->listing->resolved('extra_guest_after');
        $extraGuestFee = $context->listing->extraGuestFee();

        if (! $hasExtraGuestRule && $threshold !== null && $extraGuestFee->isPositive() && $guests > (int) $threshold) {
            $extraGuests = $guests - (int) $threshold;
            $amount = $extraGuestFee->multiply($extraGuests * $nights);

            $lines[] = new PriceLine(
                kind: 'fee',
                code: 'extra_guest',
                label: sprintf('Extra guest fee (%d guest%s)', $extraGuests, $extraGuests === 1 ? '' : 's'),
                amount: $amount,
                quantity: $extraGuests * $nights,
                unitAmount: $extraGuestFee,
                isTaxable: true,
                trace: [
                    'source' => 'listing',
                    'basis' => FeeRule::PER_GUEST_PER_NIGHT,
                    'threshold' => (int) $threshold,
                    'guests' => $guests,
                    'extra_guests' => $extraGuests,
                    'nights' => $nights,
                ],
            );
        }

        foreach ($rules as $rule) {
            $line = $this->lineFor($rule, $context, $accommodation, $currency);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function lineFor(
        FeeRule $rule,
        PricingContext $context,
        Money $accommodation,
        string $currency,
    ): ?PriceLine {
        $nights = $context->nights();
        $guests = $context->guests();

        // Threshold rules: an extra-guest fee only bites above N guests, a
        // long-stay discount fee only after N nights.
        if ($rule->applies_after_nights !== null && $nights <= (int) $rule->applies_after_nights) {
            return null;
        }

        $chargeableGuests = $guests;

        if ($rule->applies_after_guests !== null) {
            $chargeableGuests = max(0, $guests - (int) $rule->applies_after_guests);

            if ($chargeableGuests === 0) {
                return null;
            }
        }

        $unit = $rule->baseAmount();

        [$quantity, $amount, $trace] = match ($rule->charge_basis) {
            FeeRule::PER_STAY => [
                1,
                $unit,
                ['basis' => 'per_stay'],
            ],

            FeeRule::PER_NIGHT => [
                $nights,
                $unit->multiply($nights),
                ['basis' => 'per_night', 'nights' => $nights],
            ],

            FeeRule::PER_GUEST => [
                $chargeableGuests,
                $unit->multiply($chargeableGuests),
                ['basis' => 'per_guest', 'guests' => $chargeableGuests],
            ],

            FeeRule::PER_GUEST_PER_NIGHT => [
                $chargeableGuests * $nights,
                $unit->multiply($chargeableGuests * $nights),
                ['basis' => 'per_guest_per_night', 'guests' => $chargeableGuests, 'nights' => $nights],
            ],

            FeeRule::PER_PET => [
                $context->pets,
                $unit->multiply($context->pets),
                ['basis' => 'per_pet', 'pets' => $context->pets],
            ],

            FeeRule::PER_PET_PER_NIGHT => [
                $context->pets * $nights,
                $unit->multiply($context->pets * $nights),
                ['basis' => 'per_pet_per_night', 'pets' => $context->pets, 'nights' => $nights],
            ],

            FeeRule::PERCENT_OF_ACCOMMODATION => [
                1,
                $accommodation->percentage((float) $rule->percentage),
                [
                    'basis' => 'percent_of_accommodation',
                    'percentage' => (float) $rule->percentage,
                    'accommodation' => $accommodation->minorUnits,
                ],
            ],

            default => [0, Money::zero($currency), ['basis' => 'unknown']],
        };

        if ($quantity < 1 || $amount->isZero()) {
            return null;
        }

        // A cap, where the rule sets one.
        if ($rule->maximum_units !== null && $quantity > (int) $rule->maximum_units) {
            $quantity = (int) $rule->maximum_units;
            $amount = $unit->multiply($quantity);
            $trace['capped_at_units'] = $quantity;
        }

        return new PriceLine(
            kind: 'fee',
            code: $rule->code,
            label: $rule->name,
            amount: $amount,
            quantity: $quantity,
            unitAmount: $unit,
            isTaxable: $rule->is_taxable,
            isRefundable: $rule->is_refundable,
            ruleId: $rule->getKey(),
            trace: $trace + ['source' => 'fee_rule', 'unit_amount' => $unit->minorUnits],
            description: $rule->description,
        );
    }
}
