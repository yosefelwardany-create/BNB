<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Pricing\DataObjects\PricingContext;
use App\Domain\Pricing\Models\Promotion;
use App\Domain\Pricing\Models\Quote;
use App\Domain\Pricing\Services\PricingEngine;
use App\Domain\Websites\Models\Website;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a site's booking box asks for: the nights it can offer, and a price
 * for the nights a guest picks.
 */
class SiteQuoteTest extends TestCase
{
    use BuildsSites;
    use RefreshDatabase;

    private Organization $harbour;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->harbour = $this->createOrganization(['name' => 'Harbour Homes', 'base_currency' => 'EUR']);

        $this->site = Website::query()->create([
            'slug' => 'brand',
            'name' => 'Stay With Us',
            'kind' => Website::KIND_BRAND,
            'status' => Website::STATUS_LIVE,
        ]);

        $this->app->make(TenantContext::class)->clear();
    }

    public function test_the_quote_matches_the_pricing_engine(): void
    {
        $listing = $this->listing($this->harbour, 'Sea View Flat', ['cleaning_fee' => 5000]);
        $this->show($listing);
        $checkIn = CarbonImmutable::today()->addDays(30);
        $checkOut = $checkIn->addDays(3);

        $response = $this->postJson('/sites/brand/api/listings/'.$listing->slug.'/quote', [
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'adults' => 2,
            'children' => 1,
        ])->assertOk();

        $expected = $this->app->make(TenantContext::class)->runAs(
            $this->harbour,
            fn () => $this->app->make(PricingEngine::class)->quote(new PricingContext(
                listing: Listing::query()->with('property')->findOrFail($listing->getKey()),
                checkIn: $checkIn,
                checkOut: $checkOut,
                adults: 2,
                children: 1,
            )),
        );

        $response
            ->assertJsonPath('currency', 'EUR')
            ->assertJsonPath('totals.grand_total.amount', $expected->grandTotal()->minorUnits)
            ->assertJsonPath('totals.fees.amount', $expected->feesTotal()->minorUnits)
            ->assertJsonCount(3, 'nights')
            ->assertJsonPath('nights.0.date', $checkIn->toDateString())
            ->assertJsonPath('nights.0.amount.amount', 10000)
            // The standard plan's deposit: 30% today, the rest before arrival.
            ->assertJsonPath('due_now.amount', (int) round($expected->grandTotal()->minorUnits * 0.3))
            ->assertJsonPath('mode', 'request');

        $this->assertNotNull($response->json('totals.grand_total.display'));
        $this->assertNotNull($response->json('expires_at'));

        // The quote is kept, so the guest who pays is charged what was shown.
        $quote = $this->app->make(TenantContext::class)->withoutScope(fn () => Quote::query()->find($response->json('quote_id')));
        $this->assertNotNull($quote);
        $this->assertSame($this->harbour->getKey(), $quote->organization_id);
        $this->assertSame('direct', $quote->channel);
        $this->assertSame($expected->grandTotal()->minorUnits, (int) $quote->grand_total);

        // What the guest sees carries no pricing internals.
        $this->assertArrayNotHasKey('trace', $response->json('fees.0') ?? []);
        $this->assertArrayNotHasKey('steps', $response->json('nights.0'));
    }

    public function test_a_stay_inside_the_balance_window_is_paid_in_full_now(): void
    {
        $listing = $this->listing($this->harbour, 'Sea View Flat');
        $this->show($listing);
        // The balance falls due 14 days before arrival, which is already past.
        $checkIn = CarbonImmutable::today()->addDays(7);

        $response = $this->postJson('/sites/brand/api/listings/'.$listing->slug.'/quote', [
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->addDays(2)->toDateString(),
            'adults' => 2,
        ])->assertOk();

        $this->assertSame($response->json('totals.grand_total.amount'), $response->json('due_now.amount'));
        $this->assertSame([], $response->json('later'));
    }

    public function test_a_stay_further_out_shows_when_the_rest_is_due(): void
    {
        $listing = $this->listing($this->harbour, 'Sea View Flat');
        $this->show($listing);
        $checkIn = CarbonImmutable::today()->addDays(30);

        $response = $this->postJson('/sites/brand/api/listings/'.$listing->slug.'/quote', [
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->addDays(2)->toDateString(),
            'adults' => 2,
        ])->assertOk();

        $this->assertSame(6000, $response->json('due_now.amount'));
        $this->assertSame(14000, $response->json('later.0.amount.amount'));
        $this->assertSame($checkIn->subDays(14)->toDateString(), $response->json('later.0.due_on'));
    }

    public function test_a_discount_is_shown_as_an_amount_off(): void
    {
        $listing = $this->listing($this->harbour, 'Sea View Flat');
        $this->show($listing);
        $this->actingForOrganization($this->harbour);
        Promotion::query()->create([
            'organization_id' => $this->harbour->getKey(),
            'name' => 'Autumn offer',
            'discount_type' => Promotion::PERCENT,
            'discount_value' => 10,
        ]);
        $this->app->make(TenantContext::class)->clear();
        $checkIn = CarbonImmutable::today()->addDays(30);

        $response = $this->postJson('/sites/brand/api/listings/'.$listing->slug.'/quote', [
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->addDays(2)->toDateString(),
            'adults' => 2,
        ])->assertOk();

        // Shown as "Autumn offer −€20.00": the page writes the minus sign itself.
        $this->assertSame(2000, $response->json('discounts.0.amount.amount'));
        $this->assertStringNotContainsString('-', (string) $response->json('discounts.0.amount.display'));
        $this->assertSame(2000, $response->json('totals.discounts.amount'));
    }

    public function test_tonight_is_bookable_until_midnight_where_the_home_is(): void
    {
        // 21:00 on 9 December in Bogotá is already 10 December in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-12-10 02:00:00', 'UTC'));
        $listing = $this->listing($this->harbour, 'Casa Andina', ['timezone' => 'America/Bogota']);
        $this->show($listing);

        $days = collect($this->getJson('/sites/brand/api/listings/'.$listing->slug.'/calendar?month=2026-12')->assertOk()->json('days'))->keyBy('date');
        $this->assertTrue($days['2026-12-09']['available']);

        $this->postJson('/sites/brand/api/listings/'.$listing->slug.'/quote', [
            'check_in' => '2026-12-09',
            'check_out' => '2026-12-11',
            'adults' => 2,
        ])->assertOk();
    }

    public function test_yesterday_is_never_offered_where_the_home_is(): void
    {
        // 05:00 on 11 December in Tokyo is still 10 December in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-12-10 20:00:00', 'UTC'));
        $listing = $this->listing($this->harbour, 'Machiya', ['timezone' => 'Asia/Tokyo']);
        $this->show($listing);

        $days = collect($this->getJson('/sites/brand/api/listings/'.$listing->slug.'/calendar?month=2026-12')->assertOk()->json('days'))->keyBy('date');
        $this->assertFalse($days['2026-12-10']['available']);
        $this->assertTrue($days['2026-12-11']['available']);

        $this->postJson('/sites/brand/api/listings/'.$listing->slug.'/quote', [
            'check_in' => '2026-12-10',
            'check_out' => '2026-12-12',
            'adults' => 2,
        ])->assertStatus(422)->assertJsonPath('errors.check_in.0', 'Check-in cannot be in the past.');
    }

    public function test_an_instant_book_home_is_quoted_for_instant_booking(): void
    {
        $listing = $this->listing($this->harbour, 'Sea View Flat', ['instant_book' => true]);
        $this->show($listing);
        $checkIn = CarbonImmutable::today()->addDays(30);

        $this->postJson('/sites/brand/api/listings/'.$listing->slug.'/quote', [
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->addDays(2)->toDateString(),
            'adults' => 2,
        ])->assertOk()->assertJsonPath('mode', 'instant');
    }

    public function test_a_quote_for_unavailable_dates_says_why(): void
    {
        $listing = $this->listing($this->harbour, 'Sea View Flat');
        $this->show($listing);
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->book($listing, $checkIn, $checkIn->addDays(4));

        $this->postJson('/sites/brand/api/listings/'.$listing->slug.'/quote', [
            'check_in' => $checkIn->addDay()->toDateString(),
            'check_out' => $checkIn->addDays(3)->toDateString(),
            'adults' => 2,
        ])
            ->assertStatus(422)
            ->assertJsonPath('available', false)
            ->assertJsonStructure(['message', 'reasons']);

        $this->postJson('/sites/brand/api/listings/'.$listing->slug.'/quote', [
            'check_in' => $checkIn->addDays(10)->toDateString(),
            'check_out' => $checkIn->addDays(12)->toDateString(),
            'adults' => 6,
        ])
            ->assertStatus(422)
            ->assertJsonPath('reasons.0', 'This home sleeps up to 4 guests.');

        // A refusal is not a quote: nothing is stored for it.
        $this->assertSame(0, $this->app->make(TenantContext::class)->withoutScope(fn () => Quote::query()->count()));
    }

    public function test_a_quote_with_unusable_dates_is_refused_in_words(): void
    {
        $listing = $this->listing($this->harbour, 'Sea View Flat');
        $this->show($listing);
        $today = CarbonImmutable::today();

        $this->postJson('/sites/brand/api/listings/'.$listing->slug.'/quote', [
            'check_in' => $today->addDays(5)->toDateString(),
            'check_out' => $today->addDays(5)->toDateString(),
            'adults' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors('check_out');

        $this->postJson('/sites/brand/api/listings/'.$listing->slug.'/quote', [
            'check_in' => $today->subDay()->toDateString(),
            'check_out' => $today->addDay()->toDateString(),
            'adults' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors('check_in');

        $this->postJson('/sites/brand/api/listings/'.$listing->slug.'/quote', [
            'check_in' => $today->addDay()->toDateString(),
            'check_out' => $today->addDays(400)->toDateString(),
            'adults' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors('check_out');
    }

    public function test_a_listing_not_on_the_site_cannot_be_quoted(): void
    {
        $elsewhere = $this->listing($this->harbour, 'Not Here');
        $checkIn = CarbonImmutable::today()->addDays(30);

        $this->postJson('/sites/brand/api/listings/'.$elsewhere->slug.'/quote', [
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->addDays(2)->toDateString(),
            'adults' => 2,
        ])->assertNotFound();

        $this->getJson('/sites/brand/api/listings/'.$elsewhere->slug.'/calendar')->assertNotFound();
    }

    public function test_the_calendar_marks_booked_nights(): void
    {
        $listing = $this->listing($this->harbour, 'Sea View Flat', ['minimum_nights' => 2]);
        $this->show($listing);
        $month = CarbonImmutable::today()->addMonthNoOverflow()->startOfMonth();
        $this->book($listing, $month->addDays(9), $month->addDays(12));

        $response = $this->getJson('/sites/brand/api/listings/'.$listing->slug.'/calendar?month='.$month->format('Y-m'))
            ->assertOk();

        $days = collect($response->json('days'))->keyBy('date');

        // Two months from the first of the month asked for.
        $this->assertSame($month->toDateString(), $response->json('days.0.date'));
        $this->assertCount((int) $month->diffInDays($month->addMonthsNoOverflow(2)), $days);

        $this->assertTrue($days[$month->addDays(8)->toDateString()]['available']);
        $this->assertFalse($days[$month->addDays(9)->toDateString()]['available']);
        $this->assertFalse($days[$month->addDays(11)->toDateString()]['available']);
        // The morning the guest leaves, the next can arrive.
        $this->assertTrue($days[$month->addDays(12)->toDateString()]['available']);
        $this->assertSame(2, $days[$month->addDays(8)->toDateString()]['min_nights']);
        $this->assertFalse($days[$month->addDays(8)->toDateString()]['closed_to_arrival']);
    }

    public function test_the_calendar_never_offers_a_night_already_past(): void
    {
        $listing = $this->listing($this->harbour, 'Sea View Flat');
        $this->show($listing);
        $today = CarbonImmutable::today();

        $days = collect($this->getJson('/sites/brand/api/listings/'.$listing->slug.'/calendar?month='.$today->subMonths(3)->format('Y-m'))
            ->assertOk()
            ->json('days'))->keyBy('date');

        // A month gone by shows from this month instead.
        $this->assertSame($today->startOfMonth()->toDateString(), $days->keys()->first());

        if (! $today->isSameDay($today->startOfMonth())) {
            $this->assertFalse($days[$today->subDay()->toDateString()]['available']);
        }

        $this->assertTrue($days[$today->toDateString()]['available']);

        $this->getJson('/sites/brand/api/listings/'.$listing->slug.'/calendar?month=soon')
            ->assertStatus(422);
    }
}
