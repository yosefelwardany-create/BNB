<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelListingImporter;
use App\Domain\Channels\Services\ReservationImporter;
use App\Domain\Integrations\DataObjects\ChannelAvailabilityUpdate;
use App\Domain\Integrations\DataObjects\ChannelRateUpdate;
use App\Domain\Integrations\Providers\Channels\ChannexChannelAdapter;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Channex adapter.
 *
 * Two properties carry most of the weight here, both of them things their
 * certification fails integrations for.
 *
 * **One request, never a loop.** Channex's tests specify "1 API call" for a
 * multi-date, multi-restriction update, and their anti-patterns list rejects
 * "per-date or per-rate API calls where the test specifies 1 API call". Their
 * rate limit — 10 availability and 10 restriction requests per minute, per
 * property — makes a per-date loop unworkable regardless.
 *
 * **A 200 is not necessarily a success.** Channex documents that a partially
 * rejected message still answers `200 OK`, listing what it refused in
 * `meta.warnings`. An adapter that reads the status line alone clears the
 * dirty flag, stores a content hash, and leaves the refused nights wrong on
 * every connected OTA with nothing to show it happened.
 */
class ChannexAdapterTest extends TestCase
{
    use RefreshDatabase;

    private ChannexChannelAdapter $adapter;

    private Organization $organization;

    private ChannelAccount $account;

    private ChannelListing $mapping;

    private const PROPERTY_UUID = '716305c4-561a-4561-a187-7f5b8aeb5920';

    private const ROOM_TYPE_UUID = '994d1375-dbbd-4072-8724-b2ab32ce781b';

    private const RATE_PLAN_UUID = '445835fb-7956-42ac-9efc-3e6f331f0808';

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = new ChannexChannelAdapter;

        $this->organization = $this->createOrganization(['base_currency' => 'USD']);

        $property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'USD',
            'base_rate' => 10000,
            'max_occupancy' => 2,
        ]);

        $listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
            'currency' => 'USD',
            'minimum_nights' => 1,
        ]);

        $this->account = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'channex',
            'name' => 'Channex — staging',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'credentials' => ['api_key' => 'test-key'],
            'sync_availability' => true,
            'sync_rates' => true,
        ]);

        $this->mapping = ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $this->account->getKey(),
            'listing_id' => $listing->getKey(),
            'property_id' => $property->getKey(),
            // The room type is the inventory, so it is the external identity.
            'external_listing_id' => self::ROOM_TYPE_UUID,
            'metadata' => [
                'channex' => [
                    'property_id' => self::PROPERTY_UUID,
                    'rate_plan_id' => self::RATE_PLAN_UUID,
                ],
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // One request, with date ranges rather than one entry per night
    // ------------------------------------------------------------------

    public function test_a_long_availability_window_is_one_request_with_collapsed_ranges(): void
    {
        Http::fake(['*/availability' => Http::response($this->taskResponse('av-task-1'))]);

        $from = CarbonImmutable::parse('2027-03-01');
        $days = [];

        // Ten nights: five with three units left, then five sold out.
        for ($i = 0; $i < 10; $i++) {
            $days[$from->addDays($i)->toDateString()] = $i < 5 ? 3 : 0;
        }

        $result = $this->adapter->pushAvailability($this->mapping, new ChannelAvailabilityUpdate(
            from: new DateTimeImmutable('2027-03-01'),
            to: new DateTimeImmutable('2027-03-10'),
            days: $days,
        ));

        $this->assertTrue($result->successful);
        Http::assertSentCount(1);

        $sent = $this->lastRequestBody();

        // Two entries for ten nights, because consecutive equal days collapse.
        $this->assertCount(2, $sent['values']);
        $this->assertSame([
            'date_from' => '2027-03-01',
            'date_to' => '2027-03-05',
            'property_id' => self::PROPERTY_UUID,
            'room_type_id' => self::ROOM_TYPE_UUID,
            'availability' => 3,
        ], $sent['values'][0]);
        $this->assertSame(0, $sent['values'][1]['availability']);
        $this->assertSame('2027-03-06', $sent['values'][1]['date_from']);
    }

    public function test_a_single_night_is_sent_as_a_single_date(): void
    {
        Http::fake(['*/availability' => Http::response($this->taskResponse('av-task-2'))]);

        $this->adapter->pushAvailability($this->mapping, new ChannelAvailabilityUpdate(
            from: new DateTimeImmutable('2027-04-01'),
            to: new DateTimeImmutable('2027-04-01'),
            days: ['2027-04-01' => 1],
        ));

        $sent = $this->lastRequestBody();

        // `date`, not a range of one, which is what their own examples show.
        $this->assertSame('2027-04-01', $sent['values'][0]['date']);
        $this->assertArrayNotHasKey('date_from', $sent['values'][0]);
    }

    public function test_rates_and_every_restriction_travel_in_one_request(): void
    {
        Http::fake(['*/restrictions' => Http::response($this->taskResponse('rate-task-1'))]);

        $result = $this->adapter->pushRates($this->mapping, new ChannelRateUpdate(
            from: new DateTimeImmutable('2027-05-01'),
            to: new DateTimeImmutable('2027-05-03'),
            currency: 'USD',
            nightlyRates: ['2027-05-01' => 20000, '2027-05-02' => 20000, '2027-05-03' => 25000],
            minimumStay: ['2027-05-01' => 2, '2027-05-02' => 2, '2027-05-03' => 2],
            maximumStay: ['2027-05-01' => 14],
            closedToArrival: ['2027-05-01' => true, '2027-05-02' => false, '2027-05-03' => false],
            closedToDeparture: ['2027-05-01' => false, '2027-05-02' => false, '2027-05-03' => false],
            stopSell: ['2027-05-01' => false, '2027-05-02' => false, '2027-05-03' => true],
        ));

        $this->assertTrue($result->successful);

        // The whole message — price, min and max stay, arrival rules and the
        // stop-sell — is one call. Their test 7 specifies exactly this.
        Http::assertSentCount(1);

        $values = $this->lastRequestBody()['values'];

        $this->assertSame(20000, $this->entryFor($values, 'rate', '2027-05-01')['rate'] ?? null);
        $this->assertSame(25000, $this->entryFor($values, 'rate', '2027-05-03')['rate'] ?? null);
        $this->assertSame(2, $this->entryFor($values, 'min_stay_arrival', '2027-05-01')['min_stay_arrival'] ?? null);
        $this->assertSame(14, $this->entryFor($values, 'max_stay', '2027-05-01')['max_stay'] ?? null);
        $this->assertTrue($this->entryFor($values, 'closed_to_arrival', '2027-05-01')['closed_to_arrival'] ?? null);
        $this->assertTrue($this->entryFor($values, 'stop_sell', '2027-05-03')['stop_sell'] ?? null);

        // Every entry names the rate plan, because Channex keys price and
        // restrictions to the plan and not to the room type.
        foreach ($values as $entry) {
            $this->assertSame(self::RATE_PLAN_UUID, $entry['rate_plan_id']);
        }
    }

    public function test_minimum_stay_is_sent_as_the_arrival_rule_it_is(): void
    {
        Http::fake(['*/restrictions' => Http::response($this->taskResponse('rate-task-2'))]);

        $this->adapter->pushRates($this->mapping, new ChannelRateUpdate(
            from: new DateTimeImmutable('2027-05-10'),
            to: new DateTimeImmutable('2027-05-11'),
            currency: 'USD',
            nightlyRates: ['2027-05-10' => 20000, '2027-05-11' => 20000],
            minimumStay: ['2027-05-10' => 3, '2027-05-11' => 3],
        ));

        // Channex's virtual `min_stay` is refused outright on a property whose
        // min_stay_type is "both" — which is what the staging demo property
        // is. The platform checks minimum stay on the arrival date, so that is
        // the field it means.
        foreach ($this->lastRequestBody()['values'] as $entry) {
            $this->assertArrayNotHasKey('min_stay', $entry);
            $this->assertArrayNotHasKey('min_stay_through', $entry);
        }

        $this->assertSame(
            3,
            $this->entryFor($this->lastRequestBody()['values'], 'min_stay_arrival', '2027-05-10')['min_stay_arrival'] ?? null,
        );
    }

    public function test_a_price_in_another_currency_is_refused_before_anything_is_sent(): void
    {
        Http::fake();

        // The plan is priced in pounds. Sending 20000 dollar cents to it would
        // publish £200.00 — a wrong price on every OTA, with a 200 to say it
        // went well.
        $this->mapping->forceFill(['metadata' => ['channex' => [
            'property_id' => self::PROPERTY_UUID,
            'rate_plan_id' => self::RATE_PLAN_UUID,
            'currency' => 'GBP',
        ]]])->save();

        $result = $this->adapter->pushRates($this->mapping->fresh(), new ChannelRateUpdate(
            from: new DateTimeImmutable('2027-09-02'),
            to: new DateTimeImmutable('2027-09-02'),
            currency: 'USD',
            nightlyRates: ['2027-09-02' => 20000],
        ));

        $this->assertFalse($result->successful);
        $this->assertFalse($result->retryable);
        $this->assertStringContainsString('GBP', $result->errorMessage ?? '');
        $this->assertStringContainsString('USD', $result->errorMessage ?? '');
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // The task id, which certification asks to be quoted
    // ------------------------------------------------------------------

    public function test_the_task_id_comes_back_as_the_external_reference(): void
    {
        Http::fake(['*/availability' => Http::response($this->taskResponse('eb31d631-4fcc-478a-80c3-bf7a2acf0699'))]);

        $result = $this->adapter->pushAvailability($this->mapping, new ChannelAvailabilityUpdate(
            from: new DateTimeImmutable('2027-06-01'),
            to: new DateTimeImmutable('2027-06-01'),
            days: ['2027-06-01' => 2],
        ));

        // Channex returns it as data[].id with type "task". It is what their
        // support team identifies a push by, and what the certification form
        // wants per test, so it must survive back to the sync job.
        $this->assertSame('eb31d631-4fcc-478a-80c3-bf7a2acf0699', $result->externalReference);
    }

    // ------------------------------------------------------------------
    // A 200 that refused something is not a success
    // ------------------------------------------------------------------

    public function test_a_partially_rejected_two_hundred_is_a_failure(): void
    {
        Http::fake(['*/restrictions' => Http::response([
            'data' => [['id' => 'task-with-warnings', 'type' => 'task']],
            'meta' => [
                'message' => 'Success',
                'warnings' => [['date' => '2027-07-01', 'error' => 'rate_plan_not_found']],
            ],
        ], 200)]);

        $result = $this->adapter->pushRates($this->mapping, new ChannelRateUpdate(
            from: new DateTimeImmutable('2027-07-01'),
            to: new DateTimeImmutable('2027-07-01'),
            currency: 'USD',
            nightlyRates: ['2027-07-01' => 20000],
        ));

        // Channex says so itself: a rejected message still answers 200 OK.
        // Reading only the status line here would clear the dirty flag and
        // store a hash, and those nights would look synchronised forever.
        $this->assertFalse($result->successful);
        $this->assertNotEmpty($result->data['warnings'] ?? []);
        $this->assertFalse($result->retryable, 'The same content will be refused again.');
    }

    public function test_a_rejection_reads_as_the_night_and_the_reason(): void
    {
        // Verbatim from staging, sending the virtual `min_stay` to a property
        // whose min_stay_type is "both". Four hundred characters of JSON for
        // one sentence — and more than a `last_error` column holds.
        Http::fake(['*/restrictions' => Http::response([
            'data' => [],
            'meta' => ['message' => 'Success', 'warnings' => [[
                'warning' => ['min_stay' => ["property doesn't support `min_stay` restriction, please use `min_stay_through` or `min_stay_arrival`"]],
                'date' => '2027-06-15',
                'property_id' => self::PROPERTY_UUID,
                'rate_plan_id' => self::RATE_PLAN_UUID,
                'min_stay' => 4,
            ]]],
        ])]);

        $result = $this->adapter->pushRates($this->mapping, new ChannelRateUpdate(
            from: new DateTimeImmutable('2027-06-15'),
            to: new DateTimeImmutable('2027-06-15'),
            currency: 'USD',
            nightlyRates: ['2027-06-15' => 20000],
        ));

        $this->assertFalse($result->successful);
        $this->assertStringContainsString(
            "2027-06-15: min_stay property doesn't support `min_stay` restriction",
            $result->errorMessage ?? '',
        );
        $this->assertStringNotContainsString(self::PROPERTY_UUID, $result->errorMessage ?? '');
    }

    public function test_a_rate_limit_is_retryable(): void
    {
        Http::fake(['*/availability' => Http::response(['errors' => ['title' => 'Too Many Requests']], 429)]);

        $result = $this->adapter->pushAvailability($this->mapping, new ChannelAvailabilityUpdate(
            from: new DateTimeImmutable('2027-08-01'),
            to: new DateTimeImmutable('2027-08-01'),
            days: ['2027-08-01' => 1],
        ));

        $this->assertFalse($result->successful);
        $this->assertTrue($result->retryable, 'A spent ARI budget refills a minute later.');
    }

    public function test_a_rejected_payload_is_not_retryable(): void
    {
        // The body staging really answers with: a 422 whose `details` name the
        // field and the rule, nested the way the request was.
        Http::fake(['*/availability' => Http::response(['errors' => [
            'code' => 'bad_request',
            'title' => 'Bad Request',
            'details' => ['values' => ['availability' => ['must be greater than or equal to 0']]],
        ]], 422)]);

        $result = $this->adapter->pushAvailability($this->mapping, new ChannelAvailabilityUpdate(
            from: new DateTimeImmutable('2027-08-02'),
            to: new DateTimeImmutable('2027-08-02'),
            days: ['2027-08-02' => 1],
        ));

        $this->assertFalse($result->successful);

        // Retrying it only delays telling somebody the payload is wrong, and
        // burns the property's ARI budget doing so.
        $this->assertFalse($result->retryable, 'A rejected payload will be rejected again.');

        // And the reason reaches the sync job, rather than "invalid" alone.
        $this->assertStringContainsString(
            'values.availability: must be greater than or equal to 0',
            $result->errorMessage ?? '',
        );
    }

    // ------------------------------------------------------------------
    // An incomplete mapping is named, not sent
    // ------------------------------------------------------------------

    public function test_a_mapping_without_a_rate_plan_is_refused_before_anything_is_sent(): void
    {
        Http::fake();

        $this->mapping->forceFill(['metadata' => ['channex' => ['property_id' => self::PROPERTY_UUID]]])->save();

        $result = $this->adapter->pushRates($this->mapping->fresh(), new ChannelRateUpdate(
            from: new DateTimeImmutable('2027-09-01'),
            to: new DateTimeImmutable('2027-09-01'),
            currency: 'USD',
            nightlyRates: ['2027-09-01' => 20000],
        ));

        $this->assertFalse($result->successful);
        $this->assertStringContainsString('rate plan', $result->errorMessage ?? '');

        // Channex answers 422 for an unknown UUID, so sending it would turn a
        // fixable mapping problem into a sync log somebody has to read.
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Bookings: the revisions feed, and acknowledging only what we read
    // ------------------------------------------------------------------

    public function test_a_booking_revision_is_read_without_being_acknowledged(): void
    {
        $this->markBookingsImported();

        Http::preventStrayRequests();
        Http::fake([
            '*/booking_revisions/feed*' => Http::response($this->feedResponse()),
            '*/ack' => Http::response(['meta' => ['message' => 'Success']]),
        ]);

        // An ordinary pull, which reads the feed and nothing else.
        $bookings = $this->adapter->importReservations($this->account->fresh(), new DateTimeImmutable('-1 day'));

        $this->assertCount(1, $bookings);

        $booking = $bookings[0];
        $this->assertSame('9996013801', $booking->externalReservationId);
        $this->assertSame(self::ROOM_TYPE_UUID, $booking->externalListingId);
        $this->assertSame('confirmed', $booking->status);
        // "200.00" in USD, converted through the currency's own exponent.
        $this->assertSame(20000, $booking->totalAmount);
        $this->assertSame('Channex', $booking->guestLastName);
        $this->assertSame('user@channex.io', $booking->guestEmail);

        // Reading is not saving. Telling Channex "received" here would let a
        // booking the importer then could not place vanish from the feed.
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/ack'));
    }

    public function test_a_booking_is_acknowledged_once_it_is_saved(): void
    {
        $this->markBookingsImported();

        Http::preventStrayRequests();
        Http::fake([
            '*/booking_revisions/feed*' => Http::response($this->feedResponse()),
            '*/ack' => Http::response(['meta' => ['message' => 'Success']]),
        ]);

        $this->app->make(ReservationImporter::class)->importFor($this->account->fresh(), CarbonImmutable::now()->subDay());

        $this->assertTrue(Reservation::query()->where('external_reservation_id', '9996013801')->exists());

        // Acknowledged, because it is stored. Channex re-offers an
        // unacknowledged revision for thirty minutes and then emails a warning.
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/booking_revisions/03dd7198-c5b7-493c-a889-74d0c2211de7/ack')
            && $request->method() === 'POST');
    }

    public function test_a_booking_for_a_room_type_nobody_linked_stays_in_the_feed(): void
    {
        $this->markBookingsImported();

        $feed = $this->feedResponse();
        $feed['data'][0]['attributes']['rooms'][0]['room_type_id'] = 'ffff0000-0000-4000-8000-00000000000f';

        Http::preventStrayRequests();
        Http::fake([
            '*/booking_revisions/feed*' => Http::response($feed),
            '*/ack' => Http::response(['meta' => ['message' => 'Success']]),
        ]);

        $this->app->make(ReservationImporter::class)->importFor($this->account->fresh(), CarbonImmutable::now()->subDay());

        // Nowhere to put it, so not saved — and therefore not acknowledged.
        // It used to be acknowledged on reading and then dropped, which lost
        // the booking for good: Channex never offers an acknowledged one again.
        $this->assertFalse(Reservation::query()->where('external_reservation_id', '9996013801')->exists());
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/ack'));
    }

    public function test_bookings_made_before_the_connection_are_imported_once(): void
    {
        // The feed carries only what Channex has not delivered yet, so a stay
        // booked before the connection existed never appears in it.
        $booking = $this->feedResponse()['data'][0];
        $booking['type'] = 'booking';
        $booking['id'] = 'cfa33f3b-bd32-4b90-8ef9-bde2bfe986cd';
        $booking['attributes']['id'] = 'cfa33f3b-bd32-4b90-8ef9-bde2bfe986cd';

        Http::preventStrayRequests();
        Http::fake([
            '*/bookings*' => Http::response(['data' => [$booking], 'meta' => ['total' => 1, 'limit' => 100, 'page' => 1]]),
            '*/booking_revisions/feed*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
        ]);

        // The connection's first pull, then the ordinary five-minute ones.
        $importer = $this->app->make(ReservationImporter::class);
        $importer->importFor($this->account->fresh());
        $importer->importFor($this->account->fresh(), CarbonImmutable::now()->subMinutes(5));
        $importer->importFor($this->account->fresh(), CarbonImmutable::now()->subMinutes(5));

        $this->assertSame(1, Reservation::query()->where('external_reservation_id', '9996013801')->count());

        // Read once. Channex's certification refuses integrations that read
        // the bookings list as their way of getting bookings.
        $this->assertSame(1, Http::recorded(fn ($request): bool => str_contains($request->url(), '/api/v1/bookings'))->count());
        $this->assertNotNull($this->account->fresh()->settings['channex_bookings_imported_at'] ?? null);

        // A booking from the list has no revision to acknowledge.
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/ack'));
    }

    public function test_the_first_import_brings_recent_stays_that_have_ended(): void
    {
        // Every staging account's demo bookings are like this: made by
        // Channex the day the account was, for stays already over by the time
        // anybody connects. Recent history belongs here as well.
        $booking = $this->feedResponse()['data'][0];
        $booking['type'] = 'booking';
        $booking['attributes']['arrival_date'] = now()->subDays(7)->toDateString();
        $booking['attributes']['departure_date'] = now()->subDays(5)->toDateString();
        $booking['attributes']['rooms'][0]['checkin_date'] = now()->subDays(7)->toDateString();
        $booking['attributes']['rooms'][0]['checkout_date'] = now()->subDays(5)->toDateString();

        Http::preventStrayRequests();
        Http::fake([
            '*/bookings*' => Http::response(['data' => [$booking], 'meta' => ['total' => 1]]),
            '*/booking_revisions/feed*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
        ]);

        $this->app->make(ReservationImporter::class)->importFor($this->account->fresh());

        $this->assertTrue(Reservation::query()->where('external_reservation_id', '9996013801')->exists());

        // Ninety days back, not the whole history of a hotel.
        Http::assertSent(fn ($request): bool => str_contains(urldecode($request->url()), 'filter[departure_date][gte]='.now()->subDays(90)->toDateString()));
    }

    public function test_the_first_import_leaves_cancelled_bookings_behind(): void
    {
        $booking = $this->feedResponse()['data'][0];
        $booking['type'] = 'booking';
        $booking['attributes']['status'] = 'cancelled';

        Http::preventStrayRequests();
        Http::fake([
            '*/bookings*' => Http::response(['data' => [$booking], 'meta' => ['total' => 1]]),
            '*/booking_revisions/feed*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
        ]);

        $this->app->make(ReservationImporter::class)->importFor($this->account->fresh());

        // A stay that is not happening is not a booking to bring in.
        $this->assertSame(0, Reservation::query()->count());
    }

    public function test_a_revision_that_cannot_be_read_is_left_unacknowledged(): void
    {
        // No OTA reference and no room type: importing it would risk a second
        // booking on the next delivery, since the reference is what the
        // platform deduplicates on.
        $this->markBookingsImported();

        Http::preventStrayRequests();
        Http::fake([
            '*/booking_revisions/feed*' => Http::response([
                'data' => [['type' => 'booking_revision', 'id' => 'rev-broken', 'attributes' => [
                    'id' => 'rev-broken',
                    'status' => 'new',
                ]]],
            ]),
            '*/ack' => Http::response(['meta' => ['message' => 'Success']]),
        ]);

        $bookings = $this->adapter->importReservations($this->account->fresh(), new DateTimeImmutable('-1 day'));

        $this->assertSame([], $bookings);

        // Left in the feed deliberately: Channex will offer it again and
        // eventually complain, which is the right outcome for something we
        // could not understand.
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/ack'));
    }

    // ------------------------------------------------------------------
    // Discovery has to keep the identifiers a push needs
    // ------------------------------------------------------------------

    public function test_importing_the_catalogue_keeps_the_property_and_rate_plan(): void
    {
        $roomType = 'c0ffee00-0000-4000-8000-000000000001';
        $ratePlan = 'c0ffee00-0000-4000-8000-000000000002';

        Http::preventStrayRequests();
        Http::fake([
            '*/properties*' => Http::response($this->page([])),
            '*/room_types*' => Http::response($this->page([$this->roomTypeRecord($roomType, 'Twin Room')])),
            '*/rate_plans*' => Http::response($this->page([$this->ratePlanRecord($ratePlan, $roomType, 'GBP')])),
        ]);

        $this->app->make(ChannelListingImporter::class)->importFor($this->account);

        $discovered = ChannelListing::query()
            ->where('channel_account_id', $this->account->getKey())
            ->where('external_listing_id', $roomType)
            ->firstOrFail();

        // Availability is a count against a room type; price and restrictions
        // belong to a rate plan inside a property. A push needs all three, and
        // `external_listing_id` holds one — so without these the catalogue
        // would import and then nothing would ever send.
        //
        // Both parents arrive under `relationships`, not `attributes`. That is
        // what a real account returns, and reading attributes alone imported
        // every room type with neither.
        $this->assertSame(self::PROPERTY_UUID, $discovered->metadata['channex']['property_id'] ?? null);
        $this->assertSame($ratePlan, $discovered->metadata['channex']['rate_plan_id'] ?? null);

        // A room type has no currency of its own; the rate plan does.
        $this->assertSame('GBP', $discovered->metadata['channex']['currency'] ?? null);
        $this->assertSame('GBP', $discovered->metadata['currency'] ?? null);
        $this->assertSame(2, $discovered->metadata['max_guests'] ?? null);
    }

    public function test_the_catalogue_is_read_past_the_first_page(): void
    {
        // Channex refuses a page larger than 100, so an account with more
        // than that has to be walked, and a plan on page two is as real as
        // one on page one.
        $roomTypes = [];
        $ratePlans = [];

        for ($i = 1; $i <= 101; $i++) {
            $roomType = sprintf('c0ffee00-0000-4000-8000-%012d', $i);
            $roomTypes[] = $this->roomTypeRecord($roomType, 'Room '.$i);
            $ratePlans[] = $this->ratePlanRecord(sprintf('bead0000-0000-4000-8000-%012d', $i), $roomType, 'USD');
        }

        Http::fake(function ($request) use ($roomTypes, $ratePlans) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $limit = (int) ($query['pagination']['limit'] ?? 10);
            $page = (int) ($query['pagination']['page'] ?? 1);

            if ($limit > 100) {
                return Http::response(['errors' => ['code' => 'bad_request', 'title' => 'Bad Request']], 422);
            }

            if (str_contains($request->url(), '/properties')) {
                return Http::response($this->page([]));
            }

            $rows = str_contains($request->url(), '/room_types') ? $roomTypes : $ratePlans;

            return Http::response($this->page(array_slice($rows, ($page - 1) * $limit, $limit), count($rows), $limit, $page));
        });

        $payloads = $this->adapter->importListings($this->account);

        $this->assertCount(101, $payloads);

        $last = $payloads[100];
        $this->assertSame('c0ffee00-0000-4000-8000-000000000101', $last->externalListingId);
        $this->assertSame('bead0000-0000-4000-8000-000000000101', $last->extra['channex']['rate_plan_id'] ?? null);
    }

    // ------------------------------------------------------------------
    // Honesty about what it can do
    // ------------------------------------------------------------------

    public function test_the_key_typed_into_the_connect_form_is_the_one_sent(): void
    {
        // The Channels screen stores every channel's secret as `access_token`.
        // Read only as `api_key`, a connection made there sent an empty key,
        // was answered 401, and could not be certified from the product.
        $this->account->forceFill(['credentials' => ['access_token' => 'typed-in-the-ui']])->save();

        Http::fake(['*/properties*' => Http::response(['data' => [], 'meta' => ['total' => 0]])]);

        $this->assertTrue($this->adapter->testConnection($this->account->fresh())->successful);

        Http::assertSent(fn ($request): bool => $request->header('user-api-key') === ['typed-in-the-ui']);
    }

    public function test_it_claims_only_what_it_implements(): void
    {
        $this->assertTrue($this->adapter->isLive());
        $this->assertNull($this->adapter->simulationReason());

        foreach (['availability', 'pricing', 'restrictions', 'stop_sell', 'import_reservations'] as $yes) {
            $this->assertTrue($this->adapter->supports($yes), $yes.' is implemented');
        }

        // Channex has endpoints in these areas and this adapter calls none of
        // them. Claiming a capability puts a button on a screen that works.
        foreach (['publish_listings', 'messaging', 'reviews', 'webhooks', 'cancel_reservations'] as $no) {
            $this->assertFalse($this->adapter->supports($no), $no.' is not implemented');
        }
    }

    // ------------------------------------------------------------------

    /**
     * The one-time import of existing bookings has already happened, so only
     * the feed is read.
     */
    private function markBookingsImported(): void
    {
        $this->account->forceFill(['settings' => ['channex_bookings_imported_at' => now()->toIso8601String()]])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function taskResponse(string $id): array
    {
        return [
            'data' => [['id' => $id, 'type' => 'task']],
            'meta' => ['message' => 'Success', 'warnings' => []],
        ];
    }

    /**
     * A list response, with the `meta` Channex puts on every one.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function page(array $rows, ?int $total = null, int $limit = 100, int $page = 1): array
    {
        return [
            'data' => $rows,
            'meta' => [
                'total' => $total ?? count($rows),
                'limit' => $limit,
                'page' => $page,
                'order_by' => 'id',
                'order_direction' => 'asc',
            ],
        ];
    }

    /**
     * A room type as the staging API returns it: the property is a
     * relationship, and there is no currency.
     *
     * @return array<string, mixed>
     */
    private function roomTypeRecord(string $id, string $title): array
    {
        return [
            'type' => 'room_type',
            'id' => $id,
            'attributes' => [
                'id' => $id,
                'meta' => null,
                'position' => 1,
                'title' => $title,
                'content' => ['description' => null, 'photos' => []],
                'codes' => null,
                'occ_adults' => 2,
                'occ_children' => 0,
                'occ_infants' => 0,
                'capacity' => null,
                'count_of_rooms' => 1,
                'default_occupancy' => 1,
                'room_kind' => 'room',
            ],
            'relationships' => [
                'property' => ['data' => ['id' => self::PROPERTY_UUID, 'type' => 'property']],
                'facilities' => ['data' => []],
            ],
        ];
    }

    /**
     * A rate plan as the staging API returns it: the property and room type
     * are relationships, and the currency is the plan's own.
     *
     * @return array<string, mixed>
     */
    private function ratePlanRecord(string $id, string $roomTypeId, string $currency): array
    {
        return [
            'type' => 'rate_plan',
            'id' => $id,
            'attributes' => [
                'id' => $id,
                'title' => 'Best Available Rate',
                'currency' => $currency,
                'sell_mode' => 'per_room',
                'rate_mode' => 'auto',
                'options' => [['id' => $id, 'occupancy' => 2, 'is_primary' => true, 'rate' => '0.00']],
                'min_stay_arrival' => [1, 1, 1, 1, 1, 1, 1],
                'min_stay_through' => [1, 1, 1, 1, 1, 1, 1],
                'stop_sell' => [false, false, false, false, false, false, false],
            ],
            'relationships' => [
                'property' => ['data' => ['id' => self::PROPERTY_UUID, 'type' => 'property']],
                'room_type' => ['data' => ['id' => $roomTypeId, 'type' => 'room_type']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function feedResponse(): array
    {
        return [
            'meta' => ['total' => 1, 'page' => 1, 'limit' => 10],
            'data' => [[
                'type' => 'booking_revision',
                'id' => '03dd7198-c5b7-493c-a889-74d0c2211de7',
                'attributes' => [
                    'id' => '03dd7198-c5b7-493c-a889-74d0c2211de7',
                    'property_id' => self::PROPERTY_UUID,
                    'booking_id' => 'cfa33f3b-bd32-4b90-8ef9-bde2bfe986cd',
                    'unique_id' => 'BDC-9996013801',
                    'ota_reservation_code' => '9996013801',
                    'ota_name' => 'Booking.com',
                    'status' => 'new',
                    'arrival_date' => '2027-04-26',
                    'departure_date' => '2027-04-27',
                    'amount' => '200.00',
                    'currency' => 'USD',
                    'occupancy' => ['adults' => 2, 'children' => 0, 'infants' => 0],
                    'customer' => [
                        'name' => 'User',
                        'surname' => 'Channex',
                        'mail' => 'user@channex.io',
                        'phone' => '1234567890',
                        'country' => 'NL',
                        'language' => 'en',
                    ],
                    'rooms' => [[
                        'amount' => '200.00',
                        'checkin_date' => '2027-04-26',
                        'checkout_date' => '2027-04-27',
                        'rate_plan_id' => self::RATE_PLAN_UUID,
                        'room_type_id' => self::ROOM_TYPE_UUID,
                        'occupancy' => ['adults' => 2, 'children' => 0, 'infants' => 0],
                    ]],
                ],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lastRequestBody(): array
    {
        $recorded = Http::recorded();
        $last = $recorded[count($recorded) - 1][0] ?? null;

        return $last === null ? [] : (array) $last->data();
    }

    /**
     * The entry carrying a given field for a given date.
     *
     * @param  list<array<string, mixed>>  $values
     * @return array<string, mixed>|null
     */
    private function entryFor(array $values, string $field, string $date): ?array
    {
        foreach ($values as $entry) {
            if (! array_key_exists($field, $entry)) {
                continue;
            }

            $from = $entry['date'] ?? $entry['date_from'] ?? null;
            $to = $entry['date'] ?? $entry['date_to'] ?? null;

            if ($from !== null && $to !== null && $date >= $from && $date <= $to) {
                return $entry;
            }
        }

        return null;
    }
}
