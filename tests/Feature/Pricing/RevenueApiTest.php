<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\Models\RatePlan;
use App\Domain\Pricing\Models\TaxRule;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The revenue management API over HTTP.
 *
 * Four properties worth protecting:
 *
 *  - Nothing in this part of the system deletes. A rule that priced a past
 *    booking is the explanation for what that guest was charged.
 *  - A rule preview must not disturb live pricing, however briefly.
 *  - Rate plan derivation cannot form a loop.
 *  - A tax rule that has already been applied cannot have its rate edited,
 *    because that would silently restate historic liabilities.
 */
class RevenueApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'cleaning_fee' => 5000,
            'max_occupancy' => 4,
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);

        $this->admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);
    }

    // ------------------------------------------------------------------
    // Rate plans
    // ------------------------------------------------------------------

    public function test_a_derived_plan_records_its_relationship_to_its_parent(): void
    {
        $parent = $this->createPlan(['name' => 'Standard', 'is_default' => true]);

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/rate-plans', [
                'name' => 'Non-refundable',
                'parent_rate_plan_id' => $parent->getKey(),
                'derivation_type' => 'percent',
                'derivation_value' => -10,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_derived', true)
            ->assertJsonPath('data.derivation_type', 'percent');

        // The relationship is kept rather than the derivation being flattened
        // at creation: raising the standard rate should move this one too.
        $this->assertSame($parent->getKey(), $response->json('data.parent_rate_plan_id'));
    }

    public function test_derivation_cannot_form_a_loop(): void
    {
        $a = $this->createPlan(['name' => 'A']);
        $b = $this->createPlan(['name' => 'B', 'parent_rate_plan_id' => $a->getKey()]);

        // A derived from B derived from A has no base rate to start from, and
        // the engine would recurse until the process died.
        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson("/api/v1/rate-plans/{$a->getKey()}", [
                'parent_rate_plan_id' => $b->getKey(),
            ])
            ->assertStatus(422);
    }

    public function test_only_one_plan_is_default_at_a_time(): void
    {
        $first = $this->createPlan(['name' => 'First', 'is_default' => true]);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/rate-plans', ['name' => 'Second', 'is_default' => true])
            ->assertCreated();

        $this->assertFalse($first->fresh()->is_default);
    }

    public function test_a_plan_with_derived_children_cannot_be_withdrawn(): void
    {
        $parent = $this->createPlan(['name' => 'Parent']);
        $this->createPlan(['name' => 'Child', 'parent_rate_plan_id' => $parent->getKey()]);

        // Withdrawing it would leave the child pricing against nothing.
        $this->actingAsUser($this->admin, $this->organization)
            ->deleteJson("/api/v1/rate-plans/{$parent->getKey()}")
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Pricing rules
    // ------------------------------------------------------------------

    public function test_a_rule_preview_shows_the_effect_without_disturbing_live_pricing(): void
    {
        $rule = PricingRule::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Weekend uplift',
            'kind' => PricingRule::KIND_SEASONAL,
            'property_id' => $this->property->getKey(),
            'adjustment_type' => 'increase_percent',
            // Basis points: 2000 is 20%.
            'adjustment_value' => 2000,
            'is_active' => true,
        ]);

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/pricing-rules/{$rule->getKey()}/preview", [
                'listing_id' => $this->listing->getKey(),
                'from' => CarbonImmutable::today()->addDay()->toDateString(),
                'to' => CarbonImmutable::today()->addDays(8)->toDateString(),
            ]);

        $response->assertOk();

        $this->assertGreaterThan(0, $response->json('data.nights_affected'));

        $first = $response->json('data.nights.0');

        $this->assertGreaterThan(
            $first['rate_without_rule']['amount'],
            $first['rate_with_rule']['amount'],
        );

        // Crucially, the rule was never switched off to work this out.
        // Suppressing it in the database, even for a moment, would quote every
        // concurrent booking the wrong price.
        $this->assertTrue($rule->fresh()->is_active);
    }

    public function test_switching_a_rule_off_keeps_it(): void
    {
        $rule = PricingRule::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Old summer rule',
            'kind' => PricingRule::KIND_SEASONAL,
            'adjustment_type' => 'increase_fixed',
            'adjustment_value' => 2000,
        ]);

        $this->actingAsUser($this->admin, $this->organization)
            ->deleteJson("/api/v1/pricing-rules/{$rule->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // It is the explanation for what every booking it priced was charged.
        $this->assertDatabaseHas('pricing_rules', ['id' => $rule->getKey()]);
    }

    // ------------------------------------------------------------------
    // Promotions
    // ------------------------------------------------------------------

    public function test_a_promotion_code_is_matched_regardless_of_case(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/promotions', [
                'name' => 'Spring sale',
                'code' => 'SPRING',
                'discount_type' => Promotion::PERCENT,
                'discount_value' => 10,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'SPRING')
            ->assertJsonPath('data.is_automatic', false);

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/promotions/check', [
                // Lower case, as a guest would type it.
                'code' => 'spring',
                'listing_id' => $this->listing->getKey(),
                'check_in' => CarbonImmutable::today()->addDays(10)->toDateString(),
                'check_out' => CarbonImmutable::today()->addDays(13)->toDateString(),
            ]);

        $response->assertOk()->assertJsonPath('data.applies', true);
    }

    public function test_an_ineligible_code_says_why(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/promotions', [
                'name' => 'Long stays only',
                'code' => 'LONGSTAY',
                'discount_type' => Promotion::PERCENT,
                'discount_value' => 15,
                'minimum_nights' => 7,
            ])->assertCreated();

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/promotions/check', [
                'code' => 'LONGSTAY',
                'listing_id' => $this->listing->getKey(),
                'check_in' => CarbonImmutable::today()->addDays(10)->toDateString(),
                'check_out' => CarbonImmutable::today()->addDays(12)->toDateString(),
            ]);

        $response->assertOk()->assertJsonPath('data.applies', false);

        // "This code needs seven nights" is something a guest can act on;
        // "invalid code" makes them abandon.
        $this->assertNotEmpty($response->json('data.reasons'));
        $this->assertStringContainsString('7', implode(' ', $response->json('data.reasons')));
    }

    public function test_an_unknown_code_is_indistinguishable_from_an_ineligible_one(): void
    {
        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/promotions/check', [
                'code' => 'NOTACODE',
                'listing_id' => $this->listing->getKey(),
                'check_in' => CarbonImmutable::today()->addDays(10)->toDateString(),
                'check_out' => CarbonImmutable::today()->addDays(12)->toDateString(),
            ]);

        // Same shape as an ineligible code, so this endpoint cannot be used to
        // enumerate which codes exist.
        $response->assertOk()
            ->assertJsonPath('data.applies', false)
            ->assertJsonPath('data.promotion', null);
    }

    // ------------------------------------------------------------------
    // Taxes
    // ------------------------------------------------------------------

    public function test_a_tax_rule_that_has_priced_a_booking_cannot_have_its_rate_edited(): void
    {
        $tax = TaxRule::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'City tax',
            'code' => 'CITY',
            'calculation' => TaxRule::PERCENT,
            'rate' => 5,
            'currency' => 'EUR',
        ]);

        $reservation = $this->book();

        DB::table('reservation_charges')
            ->where('reservation_id', $reservation->getKey())
            ->limit(1)
            ->update(['tax_rule_id' => $tax->getKey()]);

        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson("/api/v1/tax-rules/{$tax->getKey()}", ['rate' => 7])
            ->assertStatus(422);

        // The descriptive fields stay editable: correcting a name restates
        // nothing.
        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson("/api/v1/tax-rules/{$tax->getKey()}", ['name' => 'Municipal tourist tax'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Municipal tourist tax');
    }

    public function test_ending_a_tax_rule_records_when_it_stopped_applying(): void
    {
        $tax = TaxRule::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Old VAT',
            'code' => 'OLDVAT',
            'calculation' => TaxRule::PERCENT,
            'rate' => 6,
            'currency' => 'EUR',
        ]);

        $this->actingAsUser($this->admin, $this->organization)
            ->deleteJson("/api/v1/tax-rules/{$tax->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Ended, not deleted: a tax authority will ask what applied when.
        $this->assertNotNull($tax->fresh()->effective_to);
        $this->assertDatabaseHas('tax_rules', ['id' => $tax->getKey()]);
    }

    // ------------------------------------------------------------------
    // Quotes
    // ------------------------------------------------------------------

    public function test_a_quote_holds_its_price_and_reports_availability_separately(): void
    {
        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/quotes', [
                'listing_id' => $this->listing->getKey(),
                'check_in' => CarbonImmutable::today()->addDays(10)->toDateString(),
                'check_out' => CarbonImmutable::today()->addDays(13)->toDateString(),
                'adults' => 2,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.nights', 3)
            ->assertJsonPath('data.is_honourable', true);

        $this->assertGreaterThan(0, $response->json('data.grand_total.amount'));

        // The itemisation is stored, not recomputed: every rule behind it can
        // be edited tomorrow without changing what this guest was told.
        $this->assertNotEmpty($response->json('data.breakdown'));

        // Availability is reported alongside rather than folded into the
        // price, because the two fail independently.
        $this->assertTrue($response->json('meta.availability.available'));
    }

    public function test_a_quote_for_dates_already_taken_is_still_priced(): void
    {
        $this->book(10, 13);

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/quotes', [
                'listing_id' => $this->listing->getKey(),
                'check_in' => CarbonImmutable::today()->addDays(10)->toDateString(),
                'check_out' => CarbonImmutable::today()->addDays(13)->toDateString(),
            ]);

        // "This is what those dates would cost, and they are gone" is a more
        // useful answer than a refusal — it is what lets a booking engine
        // offer alternatives.
        $response->assertCreated();

        $this->assertGreaterThan(0, $response->json('data.grand_total.amount'));
        $this->assertFalse($response->json('meta.availability.available'));
    }

    // ------------------------------------------------------------------
    // Analytics authorisation
    // ------------------------------------------------------------------

    public function test_revenue_figures_need_the_revenue_permission(): void
    {
        $cleaner = $this->createUser($this->organization, [RoleRegistry::CLEANER]);

        $this->actingAsUser($cleaner, $this->organization)
            ->getJson('/api/v1/revenue/summary')
            ->assertForbidden();

        $this->actingAsUser($this->admin, $this->organization)
            ->getJson('/api/v1/revenue/summary')
            ->assertOk()
            ->assertJsonStructure(['data' => ['occupancy_rate', 'adr', 'revpar', 'nights_sold']]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPlan(array $attributes): RatePlan
    {
        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/rate-plans', $attributes)
            ->assertCreated();

        return RatePlan::query()->findOrFail($response->json('data.id'));
    }

    private function book(int $inDays = 20, int $outDays = 23): Reservation
    {
        return $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $this->listing,
            checkIn: CarbonImmutable::today()->addDays($inDays),
            checkOut: CarbonImmutable::today()->addDays($outDays),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Ana',
                'last_name' => 'Costa',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
            bookedAt: CarbonImmutable::today(),
        ));
    }
}
