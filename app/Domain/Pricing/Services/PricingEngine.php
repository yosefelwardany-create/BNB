<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Availability\Models\CalendarDay;
use App\Domain\Listings\Models\Listing;
use App\Domain\Pricing\DataObjects\NightPrice;
use App\Domain\Pricing\DataObjects\PriceLine;
use App\Domain\Pricing\DataObjects\PriceQuote;
use App\Domain\Pricing\DataObjects\PricingContext;
use App\Domain\Pricing\DataObjects\PricingStep;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\Models\RatePlan;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Produces a fully itemised price for a stay.
 *
 * The engine is deterministic: the same context and the same rules always
 * produce the same quote. Nothing reads the clock (the booking date is part of
 * the context), nothing depends on database ordering (rules are ordered by
 * priority then id), and every step is recorded.
 *
 * Order of work, which is also the order a manager would explain it in:
 *
 *   1. Nightly rate — base rate, rate plan derivation, then pricing rules in
 *      priority order, then any explicit calendar override.
 *   2. Fees — cleaning, extra guest, pet and the rest.
 *   3. Discounts — promotions, length-of-stay, early booking.
 *   4. Taxes — last, because tax is charged on the discounted, fee-inclusive
 *      base, and compounding taxes build on the taxes before them.
 */
class PricingEngine
{
    public function __construct(
        private readonly PricingRuleEvaluator $evaluator,
        private readonly FeeCalculator $fees,
        private readonly TaxCalculator $taxes,
    ) {}

    public function quote(PricingContext $context): PriceQuote
    {
        $currency = $context->listing->currency;

        // 1. Nightly rates.
        $nights = $this->priceNights($context, $currency);

        $accommodation = Money::sum(
            array_map(fn (NightPrice $n): Money => $n->rate, $nights),
            $currency,
        );

        // 2. Fees.
        $feeLines = $this->fees->calculate($context, $accommodation);

        $feesTotal = Money::sum(
            array_map(fn (PriceLine $l): Money => $l->amount, $feeLines),
            $currency,
        );

        // 3. Discounts.
        [$discountLines, $promotionId, $notices] = $this->applyDiscounts(
            $context,
            $accommodation,
            $feesTotal,
            $nights,
        );

        $discountTotal = Money::sum(
            array_map(fn (PriceLine $l): Money => $l->amount->absolute(), $discountLines),
            $currency,
        );

        // 4. Taxes, on the discounted base.
        [$taxLines, $taxNotices] = $this->taxes->calculate(
            $context,
            $accommodation->subtract($discountTotal),
            $feeLines,
        );

        return new PriceQuote(
            currency: $currency,
            nights: $nights,
            fees: $feeLines,
            discounts: $discountLines,
            taxes: $taxLines,
            ratePlanId: $context->ratePlan?->getKey(),
            ratePlanName: $context->ratePlan?->name,
            promotionId: $promotionId,
            notices: array_merge($notices, $taxNotices),
        );
    }

    /**
     * The nightly rate for every date in a range.
     *
     * What a channel's rate calendar needs: a price per night, with no fees,
     * discounts or taxes — those are properties of a *stay*, and a calendar
     * describes nights nobody has booked yet.
     *
     * Runs the same nightly-rate path a real quote does, so what an OTA
     * publishes and what the booking engine charges cannot drift apart. It
     * deliberately does not apply length-of-stay pricing: quoting the whole
     * window as one enormous stay would earn it a long-stay discount and
     * publish a rate nobody could ever book at.
     *
     * @return array<string, Money> date => rate
     */
    public function rateCalendar(
        Listing $listing,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?RatePlan $ratePlan = null,
    ): array {
        $context = new PricingContext(
            listing: $listing,
            checkIn: $from,
            checkOut: $to,
            // Two adults, or fewer if the listing sleeps fewer. A published
            // nightly rate is quoted at standard occupancy across the whole
            // industry; rating the calendar at *maximum* occupancy would
            // publish every night with an extra-guest surcharge baked in, and
            // a single traveller would find the advertised price wrong.
            adults: min(2, $listing->maxOccupancy()),
            ratePlan: $ratePlan,
            bookingDate: CarbonImmutable::today(),
        );

        $rates = [];

        foreach ($this->priceNights($context, $listing->currency) as $night) {
            $rates[$night->date] = $night->rate;
        }

        return $rates;
    }

    /**
     * Rate every night of the stay.
     *
     * @return list<NightPrice>
     */
    private function priceNights(PricingContext $context, string $currency): array
    {
        $listing = $context->listing;

        // Rules and overrides are loaded once for the whole stay rather than
        // per night: a 30-night booking should not mean 30 round trips.
        $rules = PricingRule::query()
            ->applicableTo($listing)
            ->get()
            ->filter(fn (PricingRule $rule): bool => $rule->isInForce($context->bookedOn())
                && $this->ruleAppliesToChannel($rule, $context->channel))
            ->values();

        $overrides = CalendarDay::query()
            ->where('listing_id', $listing->getKey())
            ->between($context->checkIn->toDateString(), $context->checkOut->toDateString())
            ->get()
            ->keyBy(fn (CalendarDay $day): string => $day->calendar_date->toDateString());

        $baseRate = $this->baseRateFor($context, $currency);

        $nights = [];

        foreach ($context->nightDates() as $date) {
            $day = CarbonImmutable::parse($date);
            $steps = [];

            $rate = $baseRate;
            $steps[] = new PricingStep(
                source: 'base',
                label: 'Base rate',
                before: Money::zero($currency),
                after: $rate,
            );

            // Rate plan derivation, before any rule, so a derived plan is a
            // clean offset from its parent.
            if ($context->ratePlan?->parent_rate_plan_id !== null) {
                $before = $rate;
                $rate = $context->ratePlan->derive($rate);

                $steps[] = new PricingStep(
                    source: 'rate_plan',
                    label: $context->ratePlan->derivationDescription() ?? $context->ratePlan->name,
                    before: $before,
                    after: $rate,
                );
            }

            foreach ($rules as $rule) {
                if (! $this->evaluator->matches($rule, $context, $day)) {
                    continue;
                }

                $before = $rate;
                $rate = $this->applyAdjustment($rule, $rate);
                $rate = $this->clamp($rule, $rate);

                $steps[] = new PricingStep(
                    source: 'rule',
                    label: $rule->name,
                    before: $before,
                    after: $rate,
                    ruleId: $rule->getKey(),
                    adjustmentType: $rule->adjustment_type,
                    adjustmentValue: (int) $rule->adjustment_value,
                );

                // An exclusive rule is the final word for this night.
                if ($rule->is_exclusive) {
                    break;
                }
            }

            // A manual calendar rate always wins: an operator who typed a
            // number into the calendar meant it.
            $override = $overrides->get($date);

            if ($override?->rate_override !== null) {
                $before = $rate;
                $rate = Money::of((int) $override->rate_override, $currency);

                $steps[] = new PricingStep(
                    source: 'calendar_override',
                    label: 'Manual rate for this date',
                    before: $before,
                    after: $rate,
                );
            }

            // A negative rate is never a legitimate outcome.
            if ($rate->isNegative()) {
                $before = $rate;
                $rate = Money::zero($currency);

                $steps[] = new PricingStep(
                    source: 'rule',
                    label: 'Floored at zero',
                    before: $before,
                    after: $rate,
                );
            }

            $nights[] = new NightPrice(
                date: $date,
                rate: $rate,
                baseRate: $baseRate,
                steps: $steps,
                unitId: $context->unitId,
            );
        }

        return $nights;
    }

    /**
     * The starting nightly rate before any rule.
     */
    private function baseRateFor(PricingContext $context, string $currency): Money
    {
        $listing = $context->listing;

        // A listing selling one specific unit uses that unit's rate, which may
        // differ from the property's (a penthouse in a block of studios).
        if ($listing->unit_id !== null && $listing->unit !== null) {
            return $listing->unit->baseRate();
        }

        if ($listing->unit_type_id !== null && $listing->unitType?->base_rate !== null) {
            return Money::of((int) $listing->unitType->base_rate, $currency);
        }

        return $listing->baseRate();
    }

    private function applyAdjustment(PricingRule $rule, Money $rate): Money
    {
        $value = (int) $rule->adjustment_value;

        return match ($rule->adjustment_type) {
            PricingRule::ADJUST_SET => Money::of($value, $rate->currency),

            // Percentage adjustments carry their value in basis points
            // (10000 = 100%) so a percentage can be stored exactly in an
            // integer column alongside fixed amounts.
            PricingRule::ADJUST_INCREASE_PERCENT => $rate->add($rate->percentage($value / 100)),
            PricingRule::ADJUST_DECREASE_PERCENT => $rate->subtract($rate->percentage($value / 100)),

            PricingRule::ADJUST_INCREASE_FIXED => $rate->add(Money::of($value, $rate->currency)),
            PricingRule::ADJUST_DECREASE_FIXED => $rate->subtract(Money::of($value, $rate->currency)),

            default => $rate,
        };
    }

    /**
     * Keep a rule's output inside its own guard rails, so a mistyped
     * percentage cannot sell a villa for nothing.
     */
    private function clamp(PricingRule $rule, Money $rate): Money
    {
        if ($rule->floor_rate !== null) {
            $floor = Money::of((int) $rule->floor_rate, $rate->currency);

            if ($rate->lessThan($floor)) {
                return $floor;
            }
        }

        if ($rule->ceiling_rate !== null) {
            $ceiling = Money::of((int) $rule->ceiling_rate, $rate->currency);

            if ($rate->greaterThan($ceiling)) {
                return $ceiling;
            }
        }

        return $rate;
    }

    private function ruleAppliesToChannel(PricingRule $rule, string $channel): bool
    {
        $channels = $rule->channels;

        return $channels === null || $channels === [] || in_array($channel, $channels, true);
    }

    /**
     * Promotions, both automatic and code-driven.
     *
     * @param  list<NightPrice>  $nights
     * @return array{0: list<PriceLine>, 1: ?string, 2: list<string>}
     */
    private function applyDiscounts(
        PricingContext $context,
        Money $accommodation,
        Money $fees,
        array $nights,
    ): array {
        $lines = [];
        $notices = [];
        $appliedId = null;

        $candidates = Promotion::query()->active()->get();

        foreach ($candidates as $promotion) {
            $isCoded = $promotion->code !== null;

            // A coded promotion only applies when the guest supplied its code.
            if ($isCoded && strcasecmp((string) $promotion->code, (string) $context->promotionCode) !== 0) {
                continue;
            }

            $errors = $promotion->eligibilityErrors(
                bookingDate: $context->bookedOn(),
                checkIn: $context->checkIn,
                checkOut: $context->checkOut,
                propertyId: $context->listing->property_id,
                channel: $context->channel,
                accommodationTotal: $accommodation,
            );

            if ($errors !== []) {
                // Only explain the failure when the guest explicitly asked for
                // this promotion; an automatic one that does not apply is not
                // something they need to hear about.
                if ($isCoded) {
                    $notices[] = sprintf('Code %s was not applied: %s', $promotion->code, implode(' ', $errors));
                }

                continue;
            }

            $discount = $promotion->discount_type === Promotion::FREE_NIGHTS
                ? $this->freeNightsDiscount($promotion, $nights, $accommodation->currency)
                : $promotion->discountFor($accommodation, $fees);

            if ($discount->isZero()) {
                continue;
            }

            // Never discount below zero.
            $remaining = $accommodation->add($promotion->applies_to_fees ? $fees : Money::zero($accommodation->currency));

            if ($discount->greaterThan($remaining)) {
                $discount = $remaining;
            }

            $lines[] = new PriceLine(
                kind: 'discount',
                code: $promotion->code ?? 'promo-'.$promotion->getKey(),
                label: $promotion->name,
                amount: $discount->negate(),
                isRefundable: true,
                ruleId: $promotion->getKey(),
                trace: [
                    'discount_type' => $promotion->discount_type,
                    'discount_value' => (float) $promotion->discount_value,
                    'applied_to' => $promotion->applies_to_fees ? 'accommodation_and_fees' : 'accommodation',
                    'base' => $remaining->minorUnits,
                ],
                description: $promotion->description,
            );

            $appliedId ??= $promotion->getKey();

            // Non-combinable promotions stop here, which is the common case:
            // a guest gets the one best offer, not every offer at once.
            if (! $promotion->combinable) {
                break;
            }
        }

        return [$lines, $appliedId, $notices];
    }

    /**
     * A "stay 7, pay 6" promotion discounts the cheapest nights, which is the
     * convention guests expect and the least generous reading that is still
     * honest.
     *
     * @param  list<NightPrice>  $nights
     */
    private function freeNightsDiscount(Promotion $promotion, array $nights, string $currency): Money
    {
        $free = (int) $promotion->discount_value;

        if ($free < 1 || count($nights) <= $free) {
            return Money::zero($currency);
        }

        $rates = array_map(fn (NightPrice $n): int => $n->rate->minorUnits, $nights);
        sort($rates);

        return Money::of(array_sum(array_slice($rates, 0, $free)), $currency);
    }
}
