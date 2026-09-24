<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Pricing\Services\RevenueAnalytics;
use App\Domain\Properties\Enums\PropertyStatus;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The occupancy denominator, when the portfolio changes.
 *
 * Every occupancy figure used to be computed against the properties that are
 * active right now, multiplied by every night in the period. That is correct
 * for an estate that did not change and wrong in both directions as soon as it
 * did — and the second direction is the one that matters, because it moves a
 * number somebody has already read:
 *
 *  - A property onboarded on the 20th was charged with nineteen nights it did
 *    not own, making a good month look ordinary.
 *  - A property archived last week disappeared from last year's denominator
 *    entirely, retroactively improving every historical occupancy figure that
 *    included it.
 *
 * What stays true is the rule that motivated the old behaviour: blocking a
 * property, or taking it off the market, does not improve its occupancy.
 */
class InventoryWindowTest extends TestCase
{
    use RefreshDatabase;

    private RevenueAnalytics $analytics;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analytics = $this->app->make(RevenueAnalytics::class);
        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);
    }

    private function property(array $attributes = []): Property
    {
        return Property::factory()->active()->create(array_merge([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'cleaning_fee' => 0,
            'max_occupancy' => 4,
            'activated_at' => CarbonImmutable::today()->subYear(),
        ], $attributes));
    }

    private function listingFor(Property $property): Listing
    {
        return Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);
    }

    // -----------------------------------------------------------------------
    // Onboarding
    // -----------------------------------------------------------------------

    public function test_a_property_owns_no_nights_before_it_was_activated(): void
    {
        $this->property(['activated_at' => CarbonImmutable::today()->subDays(3)]);

        $summary = $this->analytics->summary(
            CarbonImmutable::today()->subDays(9),
            CarbonImmutable::today(),
        );

        // Ten days in the window; the property existed for four of them.
        $this->assertSame(4, $summary['nights_available']);
    }

    public function test_an_onboarding_month_is_not_charged_with_nights_nobody_owned(): void
    {
        $established = $this->property();
        $new = $this->property(['activated_at' => CarbonImmutable::today()->subDays(2)]);

        $summary = $this->analytics->summary(
            CarbonImmutable::today()->subDays(9),
            CarbonImmutable::today(),
        );

        // Ten for the established one, three for the new one — not twenty.
        $this->assertSame(13, $summary['nights_available']);

        $this->assertNotNull($established->getKey());
        $this->assertNotNull($new->getKey());
    }

    public function test_a_draft_property_is_not_inventory(): void
    {
        $this->property();

        Property::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'status' => PropertyStatus::Draft,
            'activated_at' => null,
        ]);

        $summary = $this->analytics->summary(
            CarbonImmutable::today()->subDays(9),
            CarbonImmutable::today(),
        );

        // Never been for sale, so it cannot have failed to sell.
        $this->assertSame(10, $summary['nights_available']);
    }

    // -----------------------------------------------------------------------
    // Retirement
    // -----------------------------------------------------------------------

    public function test_an_archived_property_still_counts_for_the_nights_it_owned(): void
    {
        $property = $this->property();

        $this->travelTo(CarbonImmutable::today()->subDays(4));
        $this->app->make(PropertyService::class)->archive($property->fresh());
        $this->travelBack();

        $summary = $this->analytics->summary(
            CarbonImmutable::today()->subDays(9),
            CarbonImmutable::today(),
        );

        // Ten days in the window, archived on the fifth from last. The last
        // night it owned is the one before it came off the market — a property
        // archived on the 15th did not own the night of the 15th — so five.
        // Dropping it entirely, which is what happened before, would have
        // improved every historical figure that included it.
        $this->assertSame(5, $summary['nights_available']);
    }

    public function test_archiving_records_the_day_it_left_the_market(): void
    {
        $property = $this->property();

        $this->assertNull($property->retired_at);

        $this->app->make(PropertyService::class)->archive($property);

        $this->assertNotNull($property->fresh()->retired_at);
    }

    public function test_reactivating_reopens_the_window(): void
    {
        $property = $this->property();

        $this->app->make(PropertyService::class)->archive($property);
        $this->app->make(PropertyService::class)->activate($property->fresh());

        $this->assertNull($property->fresh()->retired_at);
    }

    public function test_a_property_archived_before_the_column_existed_is_left_alone(): void
    {
        $this->property();

        // No retirement date, because nothing recorded one. Guessing a
        // plausible date would move a historical denominator to a number
        // nobody can check, so it keeps the behaviour it has always had.
        Property::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'status' => PropertyStatus::Archived,
            'activated_at' => CarbonImmutable::today()->subYear(),
            'retired_at' => null,
        ]);

        $summary = $this->analytics->summary(
            CarbonImmutable::today()->subDays(9),
            CarbonImmutable::today(),
        );

        $this->assertSame(10, $summary['nights_available']);
    }

    // -----------------------------------------------------------------------
    // The rule that has not changed
    // -----------------------------------------------------------------------

    public function test_taking_a_property_off_the_market_does_not_improve_occupancy(): void
    {
        $property = $this->property();
        $listing = $this->listingFor($property);

        $this->book($listing, 1, 3);

        $this->app->make(PropertyService::class)->deactivate($property);

        $summary = $this->analytics->summary(
            CarbonImmutable::today(),
            CarbonImmutable::today()->addDays(9),
        );

        // Deactivating is the same act as blocking every night on it, and the
        // business still owns the nights.
        $this->assertSame(10, $summary['nights_available']);
        $this->assertSame(2, $summary['nights_sold']);
        $this->assertEqualsWithDelta(20.0, $summary['occupancy_rate'], 0.001);
    }

    // -----------------------------------------------------------------------
    // Everywhere it shows up
    // -----------------------------------------------------------------------

    public function test_the_daily_series_counts_the_estate_as_it_was_on_each_day(): void
    {
        $this->property();
        $this->property(['activated_at' => CarbonImmutable::today()->subDays(2)]);

        $days = $this->analytics->daily(
            CarbonImmutable::today()->subDays(4),
            CarbonImmutable::today(),
        );

        $this->assertSame(1, $days[0]['nights_available']);
        $this->assertSame(1, $days[1]['nights_available']);
        $this->assertSame(2, $days[2]['nights_available']);
        $this->assertSame(2, $days[4]['nights_available']);
    }

    public function test_per_property_ranking_compares_like_with_like(): void
    {
        $established = $this->property();
        $new = $this->property(['activated_at' => CarbonImmutable::today()->subDays(2)]);

        $this->book($this->listingFor($established), 0, 1);
        $this->book($this->listingFor($new), 0, 1);

        $rows = collect($this->analytics->byProperty(
            CarbonImmutable::today()->subDays(9),
            CarbonImmutable::today()->addDays(1),
        ))->keyBy('property_id');

        // One night sold each. Charging both with the full period would rank
        // the new property below the old one on the strength of nights it
        // never had.
        $this->assertSame(11, $rows[$established->getKey()]['nights_available']);
        $this->assertSame(4, $rows[$new->getKey()]['nights_available']);

        $this->assertGreaterThan(
            $rows[$established->getKey()]['occupancy_rate'],
            $rows[$new->getKey()]['occupancy_rate'],
        );
    }

    private function book(Listing $listing, int $inDays, int $outDays): void
    {
        $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $listing,
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
