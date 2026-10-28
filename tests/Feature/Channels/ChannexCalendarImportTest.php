<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Availability\Models\CalendarDay;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelPuller;
use App\Domain\Channels\Services\ChannexCalendarSeeder;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A room type linked from Channex starts from Channex's prices, once.
 *
 * After that Habitat owns the calendar and sends it to Channex, which is what
 * Channex certifies. But a property made from a Channex room type arrived with
 * no price at all — £0.00 — and a calendar flat where Channex's was not, so the
 * first push would have overwritten every price somebody had set there.
 */
class ChannexCalendarImportTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY = '09a67eab-b052-46b3-b308-c7de8f493da7';

    private const ROOM_TYPE = 'cd9e2fb1-07dc-44a6-b821-8804e83f47ba';

    private const RATE_PLAN = '89cb2a47-2530-4d67-8679-a96a1d8a905f';

    private Organization $organization;

    private ChannelAccount $account;

    private Property $property;

    private Listing $listing;

    private ChannelListing $mapping;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'GBP']);

        $this->property = Property::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'GBP',
            // Unpriced: what a property made from a Channex room type with
            // no default rate arrives with, shown as £0.00.
            'base_rate' => 0,
        ]);

        $this->listing = Listing::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'GBP',
            'base_rate' => null,
            'minimum_nights' => 1,
        ]);

        $this->account = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'channex',
            'name' => 'Channex',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'credentials' => ['access_token' => 'test-key'],
        ]);

        $this->mapping = ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $this->account->getKey(),
            'listing_id' => $this->listing->getKey(),
            'property_id' => $this->property->getKey(),
            'external_listing_id' => self::ROOM_TYPE,
            'is_active' => true,
            'metadata' => ['channex' => ['property_id' => self::PROPERTY, 'rate_plan_id' => self::RATE_PLAN, 'currency' => 'GBP']],
        ]);

        Http::preventStrayRequests();
    }

    public function test_channexs_prices_and_rules_are_copied_where_they_differ(): void
    {
        $this->fakeCalendar();

        $report = $this->app->make(ChannexCalendarSeeder::class)->seed($this->account);

        $this->assertSame(1, $report['imported']);

        // The price most nights carry becomes the property's own, so the
        // calendar is not three hundred overrides of the same number.
        $this->assertSame(10000, (int) $this->property->fresh()->base_rate);

        // Only the nights that differ are written down.
        $this->assertNull($this->day(1));

        $dearer = $this->day(2);
        $this->assertSame(15000, (int) $dearer?->rate_override);
        $this->assertSame(3, (int) $dearer?->minimum_nights);

        $closed = $this->day(3);
        $this->assertNull($closed?->rate_override);
        $this->assertTrue($closed?->closed_to_arrival);
        // A stop-sell is a night closed by hand, which is what it says here too.
        $this->assertTrue($closed?->is_blocked);

        $this->assertNotNull($this->mapping->fresh()->metadata['channex']['calendar_imported_at'] ?? null);
    }

    public function test_it_happens_once(): void
    {
        $this->fakeCalendar();

        $seeder = $this->app->make(ChannexCalendarSeeder::class);
        $seeder->seed($this->account);
        $seeder->seed($this->account);

        // After the first time, Habitat owns the calendar.
        $this->assertSame(1, Http::recorded()->count());
    }

    public function test_a_calendar_already_set_in_habitat_is_left_alone(): void
    {
        $this->fakeCalendar();

        CalendarDay::query()->create([
            'organization_id' => $this->organization->getKey(),
            'listing_id' => $this->listing->getKey(),
            'calendar_date' => CarbonImmutable::today()->addDays(10)->toDateString(),
            'rate_override' => 22200,
        ]);

        $report = $this->app->make(ChannexCalendarSeeder::class)->seed($this->account);

        // Somebody has priced nights here already; Channex does not get to
        // overwrite them.
        $this->assertSame(1, $report['skipped']);
        $this->assertNull($this->day(2));
        $this->assertSame(0, (int) $this->property->fresh()->base_rate);
        $this->assertNotNull($this->mapping->fresh()->metadata['channex']['calendar_imported_at'] ?? null);
    }

    public function test_a_pull_does_it(): void
    {
        $this->fakeCalendar([
            '*/properties*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/room_types*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/rate_plans*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/api/v1/booking_revisions?*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/booking_revisions/feed*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
        ]);

        $outcome = $this->app->make(ChannelPuller::class)->pull($this->account);

        $this->assertSame(1, $outcome['availability']['imported'] ?? null);
        $this->assertSame(10000, (int) $this->property->fresh()->base_rate);
    }

    // ------------------------------------------------------------------

    private function day(int $daysAhead): ?CalendarDay
    {
        return CalendarDay::query()
            ->where('listing_id', $this->listing->getKey())
            ->whereDate('calendar_date', CarbonImmutable::today()->addDays($daysAhead)->toDateString())
            ->first();
    }

    /**
     * Staging's restrictions read: keyed by rate plan, then by date.
     *
     * @param  array<string, mixed>  $more
     */
    private function fakeCalendar(array $more = []): void
    {
        $night = fn (string $rate, int $minimum = 1, bool $closedToArrival = false, bool $stopSell = false): array => [
            'rate' => $rate,
            'min_stay_arrival' => $minimum,
            'max_stay' => 0,
            'closed_to_arrival' => $closedToArrival,
            'closed_to_departure' => false,
            'stop_sell' => $stopSell,
            'unavailable_reasons' => [],
        ];

        $date = fn (int $ahead): string => CarbonImmutable::today()->addDays($ahead)->toDateString();

        Http::fake($more + [
            '*/restrictions*' => Http::response(['data' => [self::RATE_PLAN => [
                $date(1) => $night('100.00'),
                $date(2) => $night('150.00', 3),
                $date(3) => $night('100.00', 1, true, true),
                $date(4) => $night('100.00'),
            ]]]),
        ]);
    }
}
