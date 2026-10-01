<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Pricing\Services\RevenueAnalytics;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four numbers a revenue manager runs the business on.
 *
 * What these tests are really protecting is the denominators. ADR divides by
 * nights *sold*, RevPAR by nights *available*, and getting them the wrong way
 * round produces two plausible numbers that both mislead. The rest guards
 * against the usual inflations: counting fees as room revenue, counting
 * cancelled bookings, and letting an operator improve occupancy by taking
 * rooms off sale.
 */
class RevenueAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private RevenueAnalytics $analytics;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analytics = $this->app->make(RevenueAnalytics::class);

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
    }

    public function test_occupancy_is_nights_sold_over_nights_the_business_owned(): void
    {
        // Three nights sold inside a ten-day window, one property.
        $this->book(2, 5);

        $summary = $this->analytics->summary(
            CarbonImmutable::today(),
            CarbonImmutable::today()->addDays(9),
        );

        $this->assertSame(3, $summary['nights_sold']);
        $this->assertSame(10, $summary['nights_available']);
        $this->assertEqualsWithDelta(30.0, $summary['occupancy_rate'], 0.001);
    }

    public function test_adr_and_revpar_differ_only_in_the_denominator(): void
    {
        $this->book(2, 5);

        $summary = $this->analytics->summary(
            CarbonImmutable::today(),
            CarbonImmutable::today()->addDays(9),
        );

        $revenue = $summary['accommodation_revenue']['amount'];

        // ADR divides by the three nights that sold; RevPAR by the ten the
        // business owned. Swapping them is the classic error, and it makes a
        // half-empty property look fully priced.
        $this->assertSame(intdiv($revenue, 3), $summary['adr']['amount']);
        $this->assertSame(intdiv($revenue, 10), $summary['revpar']['amount']);
        $this->assertGreaterThan($summary['revpar']['amount'], $summary['adr']['amount']);
    }

    public function test_revenue_counts_accommodation_only(): void
    {
        $reservation = $this->book(2, 5);

        $summary = $this->analytics->summary(
            CarbonImmutable::today(),
            CarbonImmutable::today()->addDays(9),
        );

        // The booking carries a cleaning fee. Room revenue is not the grand
        // total, and including fees would inflate ADR by a fixed amount per
        // stay — making short stays look more valuable than they are.
        $this->assertLessThan(
            (int) $reservation->grand_total,
            $summary['accommodation_revenue']['amount'],
        );

        $this->assertSame(
            (int) $reservation->accommodation_total,
            $summary['accommodation_revenue']['amount'],
        );
    }

    public function test_a_cancelled_booking_earns_nothing_and_occupies_nothing(): void
    {
        $reservation = $this->book(2, 5);

        $reservation->forceFill(['status' => ReservationStatus::Cancelled->value])->save();

        $summary = $this->analytics->summary(
            CarbonImmutable::today(),
            CarbonImmutable::today()->addDays(9),
        );

        $this->assertSame(0, $summary['nights_sold']);
        $this->assertSame(0, $summary['accommodation_revenue']['amount']);
        $this->assertEqualsWithDelta(0.0, $summary['occupancy_rate'], 0.001);
    }

    public function test_an_empty_portfolio_returns_zero_rather_than_dividing_by_it(): void
    {
        $empty = $this->createOrganization(['base_currency' => 'GBP']);

        $summary = $this->analytics->summary(
            CarbonImmutable::today(),
            CarbonImmutable::today()->addDays(6),
        );

        $this->assertSame(0, $summary['nights_available']);
        $this->assertEqualsWithDelta(0.0, $summary['occupancy_rate'], 0.001);
        $this->assertSame(0, $summary['adr']['amount']);
        $this->assertSame(0, $summary['revpar']['amount']);
        // Reported in the empty organization's own currency: the helper
        // rebinds the tenant, and the analytics read it rather than carrying
        // the previous tenant's over.
        $this->assertSame('GBP', $summary['currency']);
        $this->assertSame($empty->base_currency, $summary['currency']);
    }

    public function test_the_daily_series_covers_every_day_including_empty_ones(): void
    {
        $this->book(2, 4);

        $days = $this->analytics->daily(
            CarbonImmutable::today(),
            CarbonImmutable::today()->addDays(6),
        );

        $this->assertCount(7, $days);

        // A day with no bookings is a zero row, not a missing one: a chart
        // with gaps reads as missing data rather than as an empty night.
        $this->assertSame(0, $days[0]['nights_sold']);
        $this->assertSame(1, $days[2]['nights_sold']);
        $this->assertGreaterThan(0, $days[2]['adr']['amount']);

        // ...and a day with no bookings has no ADR to report, rather than an
        // ADR of zero that would drag a period average down.
        $this->assertSame(0, $days[0]['adr']['amount']);
    }

    public function test_source_mix_shares_sum_to_the_whole(): void
    {
        $direct = $this->book(2, 4);
        $ota = $this->book(10, 13);

        $ota->forceFill(['source' => 'airbnb'])->save();

        $rows = $this->analytics->bySource(
            CarbonImmutable::today(),
            CarbonImmutable::today()->addDays(20),
        );

        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(
            100.0,
            array_sum(array_column($rows, 'share_of_revenue')),
            0.05,
        );

        $this->assertSame(
            ['airbnb', 'direct'],
            collect($rows)->pluck('source')->sort()->values()->all(),
        );
    }

    public function test_pace_counts_what_is_on_the_books_for_a_future_window(): void
    {
        $this->book(40, 43);

        $pace = $this->analytics->pace(
            CarbonImmutable::today()->addDays(30),
            CarbonImmutable::today()->addDays(60),
        );

        $this->assertSame(1, $pace['reservations_on_the_books']);
        $this->assertSame(3, $pace['nights_on_the_books']);

        // Booked today for an arrival in forty days.
        $this->assertEqualsWithDelta(40.0, $pace['average_lead_time_days'], 1.0);
    }

    public function test_per_property_rows_are_ranked_by_revenue(): void
    {
        $second = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 30000,
            'max_occupancy' => 4,
        ]);

        $secondListing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $second->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);

        $this->book(2, 4);
        $this->book(2, 4, $secondListing);

        $rows = $this->analytics->byProperty(
            CarbonImmutable::today(),
            CarbonImmutable::today()->addDays(9),
        );

        $this->assertCount(2, $rows);
        $this->assertSame($second->getKey(), $rows[0]['property_id']);
        $this->assertGreaterThan(
            $rows[1]['accommodation_revenue']['amount'],
            $rows[0]['accommodation_revenue']['amount'],
        );
    }

    public function test_a_back_filled_history_counts_the_nights_it_proves_were_owned(): void
    {
        /*
         * The failure this guards against, as it was reported: a table of
         * properties showing 1,000% and 1,400% occupancy.
         *
         * The property went on the books in Habitat this morning — the factory
         * sets `activated_at` to now — and the operator immediately recorded the
         * month of real stays it had before Habitat existed for them. Counting
         * available nights from activation alone gave a denominator of one
         * against a numerator of eighteen.
         */
        $from = CarbonImmutable::today()->subDays(29);
        $to = CarbonImmutable::today();

        $this->record($from, $from->addDays(10));
        $this->record($from->addDays(12), $from->addDays(20));

        $summary = $this->analytics->summary($from, $to);

        $this->assertSame(18, $summary['nights_sold']);

        // Thirty, not one. The stays are evidence the property was inventory
        // from the first of them, and better evidence than the morning somebody
        // got round to typing it in.
        $this->assertSame(30, $summary['nights_available']);
        $this->assertEqualsWithDelta(60.0, $summary['occupancy_rate'], 0.001);
        $this->assertLessThanOrEqual(100.0, $summary['occupancy_rate']);
    }

    public function test_a_back_filled_history_fixes_the_per_property_table_too(): void
    {
        // The screen the figures were wrong on is the per-property one, which
        // computes its own denominator.
        $from = CarbonImmutable::today()->subDays(29);
        $to = CarbonImmutable::today();

        $this->record($from, $from->addDays(10));

        $rows = $this->analytics->byProperty($from, $to);

        $this->assertCount(1, $rows);
        $this->assertSame(10, $rows[0]['nights_sold']);
        $this->assertSame(30, $rows[0]['nights_available']);
        $this->assertLessThanOrEqual(100.0, $rows[0]['occupancy_rate']);

        // RevPAR shares the denominator, so it was overstated by the same
        // factor — the number an owner would have been shown.
        $this->assertSame(
            intdiv($rows[0]['accommodation_revenue']['amount'], 30),
            $rows[0]['revpar']['amount'],
        );
    }

    public function test_a_property_with_no_stays_still_counts_only_from_activation(): void
    {
        // The widening is evidence-led, not a blanket "assume it always
        // existed": a property genuinely onboarded mid-period must not be
        // charged with nights before it, or its occupancy reads low for a
        // month it was not trading.
        $late = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'max_occupancy' => 4,
            'activated_at' => CarbonImmutable::today()->subDays(4),
        ]);

        $lateListing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $late->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);

        $this->book(1, 3, $lateListing);

        $rows = collect($this->analytics->byProperty(
            CarbonImmutable::today()->subDays(29),
            CarbonImmutable::today()->addDays(9),
        ))->firstWhere('property_id', $late->getKey());

        // Activated four days ago, booked two nights ahead: from day -4 to the
        // end of the window is fourteen nights, and none of the twenty-five
        // before it.
        $this->assertSame(14, $rows['nights_available']);
    }

    /**
     * A stay that already happened, written down after the fact.
     */
    private function record(CarbonImmutable $checkIn, CarbonImmutable $checkOut): Reservation
    {
        return $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $this->listing,
            checkIn: $checkIn,
            checkOut: $checkOut,
            adults: 2,
            status: ReservationStatus::CheckedOut,
            guestAttributes: [
                'first_name' => 'Ana',
                'last_name' => 'Costa',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
            bookedAt: $checkIn->subDays(5),
            recordsExistingStay: true,
        ));
    }

    private function book(int $inDays, int $outDays, ?Listing $listing = null): Reservation
    {
        return $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $listing ?? $this->listing,
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
