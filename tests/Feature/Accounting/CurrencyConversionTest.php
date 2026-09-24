<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\Models\ExchangeRate;
use App\Domain\Accounting\Services\CurrencyConverter;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Support\PlanFeature;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Users\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-currency.
 *
 * The schema has always carried base_amount, base_currency and exchange_rate,
 * and the arithmetic against them was right — but nothing supplied a rate, so
 * every conversion ran at parity. That is a gap with a particular shape: it is
 * invisible while a customer trades in one currency and silently wrong the day
 * they do not.
 *
 * What these protect above all is the refusal. A conversion at a guessed rate is
 * the worst available outcome — plausible, silently wrong, and wrong by exactly
 * the amount nobody notices until an audit — so an unknown rate must produce an
 * error and never a number.
 */
class CurrencyConversionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private CurrencyConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converter = $this->app->make(CurrencyConverter::class);

        ['organization' => $this->organization, 'user' => $this->user] = $this->createTenantWithAdmin(
            ['base_currency' => 'EUR'],
        );

        $this->actingAsUser($this->user, $this->organization);
    }

    private function rate(string $from, string $to, float $rate, ?string $on = null): ExchangeRate
    {
        return ExchangeRate::query()->create([
            'base_currency' => $from,
            'quote_currency' => $to,
            'rate_date' => $on ?? CarbonImmutable::today()->toDateString(),
            'rate' => $rate,
            'source' => 'test',
        ]);
    }

    // ---------------------------------------------------------------------
    // The refusal
    // ---------------------------------------------------------------------

    public function test_an_unknown_rate_is_refused_rather_than_guessed(): void
    {
        $this->assertNull($this->converter->rateFor('GBP', 'EUR'));

        $this->expectExceptionMessageMatches('/No exchange rate is known for GBP to EUR/');

        $this->converter->convert(Money::of(10000, 'GBP'), 'EUR');
    }

    public function test_the_quote_endpoint_says_a_rate_is_unknown_instead_of_answering_one(): void
    {
        $this->getJson('/api/v1/exchange-rates/quote?from=GBP&to=EUR&amount=10000')
            ->assertOk()
            ->assertJsonPath('data.known', false)
            ->assertJsonPath('data.rate', null)
            // An interface that showed 1.0 here would teach somebody to trust it.
            ->assertJsonPath('data.converted', null);
    }

    public function test_a_currency_against_itself_needs_no_rate(): void
    {
        // Not a lookup, and must not be able to fail — otherwise every
        // single-currency organization depends on a table it has no reason to
        // populate.
        $this->assertSame(1.0, $this->converter->rateFor('EUR', 'EUR'));

        $this->assertSame(
            5000,
            $this->converter->convert(Money::of(5000, 'EUR'), 'EUR')->minorUnits,
        );
    }

    // ---------------------------------------------------------------------
    // Reading rates
    // ---------------------------------------------------------------------

    public function test_it_converts_at_a_stored_rate(): void
    {
        $this->rate('GBP', 'EUR', 1.17);

        $this->assertSame(1.17, $this->converter->rateFor('GBP', 'EUR'));

        $converted = $this->converter->convert(Money::of(10000, 'GBP'), 'EUR');

        $this->assertSame(11700, $converted->minorUnits);
        $this->assertSame('EUR', $converted->currency);
    }

    public function test_it_derives_the_inverse_when_only_one_direction_is_stored(): void
    {
        $this->rate('EUR', 'GBP', 0.85);

        // Storing both halves invites the two from drifting apart, so the
        // reciprocal is computed.
        $rate = $this->converter->rateFor('GBP', 'EUR');

        $this->assertNotNull($rate);
        $this->assertEqualsWithDelta(1 / 0.85, $rate, 0.0000001);
    }

    public function test_it_uses_the_most_recent_rate_on_or_before_the_date(): void
    {
        $this->rate('USD', 'EUR', 0.90, CarbonImmutable::today()->subDays(10)->toDateString());
        $this->rate('USD', 'EUR', 0.95, CarbonImmutable::today()->subDays(3)->toDateString());

        // Markets close: a Sunday converts at Friday's rate.
        $this->assertSame(0.95, $this->converter->rateFor('USD', 'EUR', CarbonImmutable::today()));

        // And a date in the past converts at what applied then, not at today's.
        $this->assertSame(
            0.90,
            $this->converter->rateFor('USD', 'EUR', CarbonImmutable::today()->subDays(5)),
        );
    }

    public function test_a_rate_before_the_earliest_known_one_is_unknown(): void
    {
        $this->rate('USD', 'EUR', 0.90, CarbonImmutable::today()->toDateString());

        // Reaching forward from an earlier date would be inventing history.
        $this->assertNull(
            $this->converter->rateFor('USD', 'EUR', CarbonImmutable::today()->subDays(30)),
        );
    }

    // ---------------------------------------------------------------------
    // Recording
    // ---------------------------------------------------------------------

    public function test_recording_the_same_day_twice_corrects_rather_than_duplicates(): void
    {
        $this->postJson('/api/v1/exchange-rates', [
            'base_currency' => 'usd',
            'quote_currency' => 'eur',
            'rate' => 0.91,
        ])->assertCreated();

        $this->postJson('/api/v1/exchange-rates', [
            'base_currency' => 'USD',
            'quote_currency' => 'EUR',
            'rate' => 0.93,
        ])->assertCreated();

        // Two rates for one day would make every conversion a coin toss.
        $this->assertSame(1, ExchangeRate::query()->count());
        $this->assertSame(0.93, $this->converter->rateFor('USD', 'EUR'));
    }

    public function test_a_rate_of_zero_or_against_itself_is_refused(): void
    {
        $this->postJson('/api/v1/exchange-rates', [
            'base_currency' => 'USD',
            'quote_currency' => 'EUR',
            'rate' => 0,
        ])->assertStatus(422);

        $this->postJson('/api/v1/exchange-rates', [
            'base_currency' => 'EUR',
            'quote_currency' => 'EUR',
            'rate' => 1,
        ])->assertStatus(422);
    }

    public function test_the_index_admits_that_nothing_subscribes_to_a_market_feed(): void
    {
        $this->rate('USD', 'EUR', 0.92);

        $this->getJson('/api/v1/exchange-rates')
            ->assertOk()
            ->assertJsonPath('meta.provider', 'stored')
            // The same honesty rule as every other integration point.
            ->assertJsonPath('meta.is_live', false)
            ->assertJsonPath('meta.simulation_reason', fn (?string $r): bool => $r !== null);
    }

    // ---------------------------------------------------------------------
    // Where it actually bites: a booking in a foreign currency
    // ---------------------------------------------------------------------

    public function test_a_booking_in_a_foreign_currency_records_the_rate_that_applied(): void
    {
        $this->rate('GBP', 'EUR', 1.18, CarbonImmutable::today()->subDays(2)->toDateString());

        $reservation = $this->bookInGbp(bookedDaysAgo: 2);

        // Not 1.0, which is what it was before anything supplied a rate.
        $this->assertEqualsWithDelta(1.18, (float) $reservation->exchange_rate, 0.0000001);
        $this->assertSame('GBP', $reservation->currency);
        $this->assertSame('EUR', $reservation->base_currency);

        // And the base total is the converted one.
        $this->assertSame(
            (int) round((int) $reservation->grand_total * 1.18),
            (int) $reservation->base_grand_total,
        );
    }

    public function test_a_booking_uses_the_rate_from_its_booking_date_not_today(): void
    {
        $this->rate('GBP', 'EUR', 1.10, CarbonImmutable::today()->subDays(30)->toDateString());
        $this->rate('GBP', 'EUR', 1.25, CarbonImmutable::today()->toDateString());

        $reservation = $this->bookInGbp(bookedDaysAgo: 30);

        // A stay booked last month and reported on today converts at last
        // month's rate, or last year's accounts move every time somebody opens
        // them.
        $this->assertEqualsWithDelta(1.10, (float) $reservation->exchange_rate, 0.0000001);
    }

    public function test_a_foreign_currency_booking_is_refused_when_no_rate_is_known(): void
    {
        $this->expectExceptionMessageMatches('/No exchange rate is known/');

        $this->bookInGbp();
    }

    public function test_multi_currency_requires_the_plan_feature(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Single currency',
            'slug' => 'single-'.uniqid(),
            // Every other feature, but not this one.
            'features' => array_values(array_diff(PlanFeature::keys(), [PlanFeature::MULTI_CURRENCY])),
        ]);

        $this->organization->forceFill(['plan_id' => $plan->getKey()])->save();
        $this->rate('GBP', 'EUR', 1.18);

        $this->expectExceptionMessageMatches('/requires multi-currency/');

        $this->bookInGbp(organization: $this->organization->fresh());
    }

    private function bookInGbp(int $bookedDaysAgo = 0, ?Organization $organization = null)
    {
        $organization ??= $this->organization;

        $property = Property::factory()->active()->create([
            'organization_id' => $organization->getKey(),
            'currency' => 'GBP',
            'base_rate' => 12000,
            'cleaning_fee' => 0,
            'max_occupancy' => 4,
        ]);

        $listing = Listing::factory()->published()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $property->getKey(),
            'currency' => 'GBP',
            'minimum_nights' => 1,
        ]);

        return app(ReservationService::class)->create(new ReservationRequest(
            listing: $listing,
            checkIn: CarbonImmutable::today($property->timezone)->addDays(5),
            checkOut: CarbonImmutable::today($property->timezone)->addDays(8),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'James',
                'last_name' => 'Whitfield',
                'email' => 'james-'.uniqid().'@guests.test',
            ],
            bookedAt: CarbonImmutable::today()->subDays($bookedDaysAgo),
        ));
    }
}
