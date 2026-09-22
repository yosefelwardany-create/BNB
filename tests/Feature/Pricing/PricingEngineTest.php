<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Domain\Availability\Models\CalendarDay;
use App\Domain\Listings\Models\Listing;
use App\Domain\Pricing\DataObjects\PricingContext;
use App\Domain\Pricing\Models\FeeRule;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\Models\RatePlan;
use App\Domain\Pricing\Services\PricingEngine;
use App\Domain\Properties\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pricing must be deterministic and explainable: the same inputs always
 * produce the same quote, and every figure carries the reasoning that produced
 * it.
 */
class PricingEngineTest extends TestCase
{
    use RefreshDatabase;

    private PricingEngine $engine;

    private Property $property;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = $this->app->make(PricingEngine::class);

        $organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,        // 100.00
            'cleaning_fee' => 5000,      // 50.00
            'max_occupancy' => 6,
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);
    }

    public function test_a_simple_stay_is_the_base_rate_times_nights_plus_the_cleaning_fee(): void
    {
        $quote = $this->engine->quote($this->context(3));

        $this->assertSame(30000, $quote->accommodationTotal()->minorUnits);
        $this->assertSame(5000, $quote->feesTotal()->minorUnits);
        $this->assertSame(35000, $quote->grandTotal()->minorUnits);
        $this->assertCount(3, $quote->nights);
    }

    public function test_every_night_records_how_its_rate_was_reached(): void
    {
        PricingRule::query()->create([
            'organization_id' => $this->property->organization_id,
            'property_id' => $this->property->getKey(),
            'name' => 'Summer premium',
            'kind' => PricingRule::KIND_SEASONAL,
            'adjustment_type' => PricingRule::ADJUST_INCREASE_PERCENT,
            'adjustment_value' => 2000,      // basis points: 20%
            'priority' => 10,
        ]);

        $quote = $this->engine->quote($this->context(2));

        $first = $quote->nights[0];

        $this->assertSame(12000, $first->rate->minorUnits);

        // Base, then the rule — in that order, each with before and after.
        $this->assertCount(2, $first->steps);
        $this->assertSame('base', $first->steps[0]->source);
        $this->assertSame('Summer premium', $first->steps[1]->label);
        $this->assertSame(10000, $first->steps[1]->before->minorUnits);
        $this->assertSame(12000, $first->steps[1]->after->minorUnits);
        $this->assertStringContainsString('Summer premium', $first->explanation());
    }

    public function test_rules_apply_in_priority_order_and_compound(): void
    {
        $organizationId = $this->property->organization_id;

        PricingRule::query()->create([
            'organization_id' => $organizationId,
            'property_id' => $this->property->getKey(),
            'name' => 'First: +10%',
            'kind' => PricingRule::KIND_CUSTOM,
            'adjustment_type' => PricingRule::ADJUST_INCREASE_PERCENT,
            'adjustment_value' => 1000,
            'priority' => 10,
        ]);

        PricingRule::query()->create([
            'organization_id' => $organizationId,
            'property_id' => $this->property->getKey(),
            'name' => 'Second: +5.00 fixed',
            'kind' => PricingRule::KIND_CUSTOM,
            'adjustment_type' => PricingRule::ADJUST_INCREASE_FIXED,
            'adjustment_value' => 500,
            'priority' => 20,
        ]);

        $quote = $this->engine->quote($this->context(1));

        // 100.00 → +10% = 110.00 → +5.00 = 115.00
        $this->assertSame(11500, $quote->nights[0]->rate->minorUnits);
    }

    public function test_an_exclusive_rule_stops_later_rules(): void
    {
        $organizationId = $this->property->organization_id;

        PricingRule::query()->create([
            'organization_id' => $organizationId,
            'property_id' => $this->property->getKey(),
            'name' => 'Fixed event rate',
            'kind' => PricingRule::KIND_SEASONAL,
            'adjustment_type' => PricingRule::ADJUST_SET,
            'adjustment_value' => 50000,
            'priority' => 10,
            'is_exclusive' => true,
        ]);

        PricingRule::query()->create([
            'organization_id' => $organizationId,
            'property_id' => $this->property->getKey(),
            'name' => 'Should not run',
            'kind' => PricingRule::KIND_CUSTOM,
            'adjustment_type' => PricingRule::ADJUST_INCREASE_PERCENT,
            'adjustment_value' => 5000,
            'priority' => 20,
        ]);

        $quote = $this->engine->quote($this->context(1));

        $this->assertSame(50000, $quote->nights[0]->rate->minorUnits);
    }

    public function test_length_of_stay_conditions_are_respected(): void
    {
        PricingRule::query()->create([
            'organization_id' => $this->property->organization_id,
            'property_id' => $this->property->getKey(),
            'name' => 'Weekly discount',
            'kind' => PricingRule::KIND_LENGTH_OF_STAY,
            'conditions' => ['nights_min' => 7],
            'adjustment_type' => PricingRule::ADJUST_DECREASE_PERCENT,
            'adjustment_value' => 1500,
            'priority' => 10,
        ]);

        // Below the threshold: no discount.
        $this->assertSame(10000, $this->engine->quote($this->context(3))->nights[0]->rate->minorUnits);

        // At the threshold: 15% off.
        $this->assertSame(8500, $this->engine->quote($this->context(7))->nights[0]->rate->minorUnits);
    }

    public function test_a_rule_with_an_unknown_condition_does_not_apply(): void
    {
        // A typo must never silently widen a rule's reach.
        PricingRule::query()->create([
            'organization_id' => $this->property->organization_id,
            'property_id' => $this->property->getKey(),
            'name' => 'Typo rule',
            'kind' => PricingRule::KIND_CUSTOM,
            'conditions' => ['nights_minimum' => 1],
            'adjustment_type' => PricingRule::ADJUST_SET,
            'adjustment_value' => 1,
            'priority' => 10,
        ]);

        $this->assertSame(10000, $this->engine->quote($this->context(2))->nights[0]->rate->minorUnits);
    }

    public function test_floor_and_ceiling_clamp_a_rule(): void
    {
        PricingRule::query()->create([
            'organization_id' => $this->property->organization_id,
            'property_id' => $this->property->getKey(),
            'name' => 'Aggressive discount with a floor',
            'kind' => PricingRule::KIND_LAST_MINUTE,
            'adjustment_type' => PricingRule::ADJUST_DECREASE_PERCENT,
            'adjustment_value' => 9000,      // 90% off
            'floor_rate' => 6000,            // but never below 60.00
            'priority' => 10,
        ]);

        $this->assertSame(6000, $this->engine->quote($this->context(1))->nights[0]->rate->minorUnits);
    }

    public function test_a_manual_calendar_rate_overrides_every_rule(): void
    {
        $date = CarbonImmutable::now($this->property->timezone)->addDays(30)->toDateString();

        PricingRule::query()->create([
            'organization_id' => $this->property->organization_id,
            'property_id' => $this->property->getKey(),
            'name' => 'Should be overridden',
            'kind' => PricingRule::KIND_CUSTOM,
            'adjustment_type' => PricingRule::ADJUST_INCREASE_PERCENT,
            'adjustment_value' => 5000,
            'priority' => 10,
        ]);

        CalendarDay::query()->create([
            'organization_id' => $this->property->organization_id,
            'listing_id' => $this->listing->getKey(),
            'calendar_date' => $date,
            'rate_override' => 22500,
        ]);

        $quote = $this->engine->quote($this->context(2, 30));

        $steps = $quote->nights[0]->steps;

        $this->assertSame(22500, $quote->nights[0]->rate->minorUnits);
        // The override is the last word, applied after every rule.
        $this->assertSame('calendar_override', $steps[count($steps) - 1]->source);
    }

    public function test_a_derived_rate_plan_offsets_its_parent(): void
    {
        $standard = RatePlan::query()->create([
            'organization_id' => $this->property->organization_id,
            'name' => 'Standard',
            'currency' => 'EUR',
            'is_default' => true,
        ]);

        $nonRefundable = RatePlan::query()->create([
            'organization_id' => $this->property->organization_id,
            'name' => 'Non-refundable',
            'currency' => 'EUR',
            'parent_rate_plan_id' => $standard->getKey(),
            'derivation_type' => 'percent',
            'derivation_value' => -10,
        ]);

        $quote = $this->engine->quote(new PricingContext(
            listing: $this->listing,
            checkIn: $this->futureDate(20),
            checkOut: $this->futureDate(22),
            ratePlan: $nonRefundable->load('parent'),
            bookingDate: $this->futureDate(0),
        ));

        $this->assertSame(9000, $quote->nights[0]->rate->minorUnits);
        $this->assertSame('Non-refundable', $quote->ratePlanName);
    }

    public function test_a_per_night_fee_multiplies_by_the_stay_length(): void
    {
        FeeRule::query()->create([
            'organization_id' => $this->property->organization_id,
            'property_id' => $this->property->getKey(),
            'name' => 'Parking',
            'code' => 'parking',
            'kind' => 'parking',
            'charge_basis' => FeeRule::PER_NIGHT,
            'amount' => 1500,
            'currency' => 'EUR',
        ]);

        $quote = $this->engine->quote($this->context(4));

        $parking = collect($quote->fees)->firstWhere('code', 'parking');

        $this->assertNotNull($parking);
        $this->assertSame(6000, $parking->amount->minorUnits);
        $this->assertSame(4, $parking->quantity);
        $this->assertSame('per_night', $parking->trace['basis']);
    }

    public function test_an_extra_guest_fee_only_applies_above_its_threshold(): void
    {
        $this->listing->forceFill([
            'extra_guest_after' => 2,
            'extra_guest_fee' => 2000,
        ])->save();

        // Two guests: no extra charge.
        $this->assertNull(
            collect($this->engine->quote($this->context(3, 10, 2))->fees)->firstWhere('code', 'extra_guest')
        );

        // Four guests for three nights: two extra guests × 20.00 × 3 nights.
        $line = collect($this->engine->quote($this->context(3, 10, 4))->fees)->firstWhere('code', 'extra_guest');

        $this->assertNotNull($line);
        $this->assertSame(12000, $line->amount->minorUnits);
    }

    public function test_a_percentage_promotion_discounts_the_accommodation(): void
    {
        Promotion::query()->create([
            'organization_id' => $this->property->organization_id,
            'name' => 'Spring sale',
            'code' => 'SPRING',
            'discount_type' => Promotion::PERCENT,
            'discount_value' => 15,
        ]);

        $quote = $this->engine->quote(new PricingContext(
            listing: $this->listing,
            checkIn: $this->futureDate(20),
            checkOut: $this->futureDate(24),
            promotionCode: 'SPRING',
            bookingDate: $this->futureDate(0),
        ));

        // 4 nights × 100.00 = 400.00; 15% = 60.00.
        $this->assertSame(6000, $quote->discountsTotal()->minorUnits);
        $this->assertSame(40000 + 5000 - 6000, $quote->grandTotal()->minorUnits);
    }

    public function test_an_ineligible_code_is_reported_rather_than_silently_ignored(): void
    {
        Promotion::query()->create([
            'organization_id' => $this->property->organization_id,
            'name' => 'Long stays only',
            'code' => 'LONGSTAY',
            'discount_type' => Promotion::PERCENT,
            'discount_value' => 20,
            'minimum_nights' => 14,
        ]);

        $quote = $this->engine->quote(new PricingContext(
            listing: $this->listing,
            checkIn: $this->futureDate(20),
            checkOut: $this->futureDate(23),
            promotionCode: 'LONGSTAY',
            bookingDate: $this->futureDate(0),
        ));

        $this->assertSame(0, $quote->discountsTotal()->minorUnits);
        $this->assertNotEmpty($quote->notices);
        $this->assertStringContainsString('LONGSTAY', $quote->notices[0]);
        $this->assertStringContainsString('14 nights', $quote->notices[0]);
    }

    public function test_a_free_nights_promotion_discounts_the_cheapest_nights(): void
    {
        // Make one night cheaper so "cheapest first" is observable.
        $date = CarbonImmutable::now($this->property->timezone)->addDays(21)->toDateString();

        CalendarDay::query()->create([
            'organization_id' => $this->property->organization_id,
            'listing_id' => $this->listing->getKey(),
            'calendar_date' => $date,
            'rate_override' => 4000,
        ]);

        Promotion::query()->create([
            'organization_id' => $this->property->organization_id,
            'name' => 'Stay 4 pay 3',
            'code' => 'FOURFORTHREE',
            'discount_type' => Promotion::FREE_NIGHTS,
            'discount_value' => 1,
        ]);

        $quote = $this->engine->quote(new PricingContext(
            listing: $this->listing,
            checkIn: $this->futureDate(20),
            checkOut: $this->futureDate(24),
            promotionCode: 'FOURFORTHREE',
            bookingDate: $this->futureDate(0),
        ));

        // The 40.00 night is the one given away, not a 100.00 one.
        $this->assertSame(4000, $quote->discountsTotal()->minorUnits);
    }

    public function test_pricing_is_reproducible(): void
    {
        PricingRule::query()->create([
            'organization_id' => $this->property->organization_id,
            'property_id' => $this->property->getKey(),
            'name' => 'Weekend premium',
            'kind' => PricingRule::KIND_DAY_OF_WEEK,
            'days_of_week' => [5, 6],
            'adjustment_type' => PricingRule::ADJUST_INCREASE_PERCENT,
            'adjustment_value' => 2500,
            'priority' => 10,
        ]);

        $first = $this->engine->quote($this->context(7));
        $second = $this->engine->quote($this->context(7));

        $this->assertSame(
            $first->grandTotal()->minorUnits,
            $second->grandTotal()->minorUnits,
        );

        $this->assertSame(
            array_map(fn ($n): int => $n->rate->minorUnits, $first->nights),
            array_map(fn ($n): int => $n->rate->minorUnits, $second->nights),
        );
    }

    public function test_the_quote_serialises_its_full_breakdown(): void
    {
        $array = $this->engine->quote($this->context(2))->toArray();

        $this->assertArrayHasKey('nights', $array);
        $this->assertArrayHasKey('fees', $array);
        $this->assertArrayHasKey('taxes', $array);
        $this->assertArrayHasKey('totals', $array);
        $this->assertArrayHasKey('steps', $array['nights'][0]);
        $this->assertSame('EUR', $array['currency']);
    }

    // ------------------------------------------------------------------

    private function context(int $nights, int $startOffset = 20, int $adults = 2): PricingContext
    {
        return new PricingContext(
            listing: $this->listing,
            checkIn: $this->futureDate($startOffset),
            checkOut: $this->futureDate($startOffset + $nights),
            adults: $adults,
            bookingDate: $this->futureDate(0),
        );
    }

    private function futureDate(int $offsetDays): CarbonImmutable
    {
        return CarbonImmutable::now($this->property->timezone)->startOfDay()->addDays($offsetDays);
    }
}
