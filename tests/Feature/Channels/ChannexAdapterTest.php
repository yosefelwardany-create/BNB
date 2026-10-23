<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelListingImporter;
use App\Domain\Integrations\DataObjects\ChannelAvailabilityUpdate;
use App\Domain\Integrations\DataObjects\ChannelRateUpdate;
use App\Domain\Integrations\Providers\Channels\ChannexChannelAdapter;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
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
        $this->assertSame(2, $this->entryFor($values, 'min_stay', '2027-05-01')['min_stay'] ?? null);
        $this->assertSame(14, $this->entryFor($values, 'max_stay', '2027-05-01')['max_stay'] ?? null);
        $this->assertTrue($this->entryFor($values, 'closed_to_arrival', '2027-05-01')['closed_to_arrival'] ?? null);
        $this->assertTrue($this->entryFor($values, 'stop_sell', '2027-05-03')['stop_sell'] ?? null);

        // Every entry names the rate plan, because Channex keys price and
        // restrictions to the plan and not to the room type.
        foreach ($values as $entry) {
            $this->assertSame(self::RATE_PLAN_UUID, $entry['rate_plan_id']);
        }
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
        Http::fake(['*/availability' => Http::response(['errors' => ['title' => 'Validation failed']], 422)]);

        $result = $this->adapter->pushAvailability($this->mapping, new ChannelAvailabilityUpdate(
            from: new DateTimeImmutable('2027-08-02'),
            to: new DateTimeImmutable('2027-08-02'),
            days: ['2027-08-02' => 1],
        ));

        $this->assertFalse($result->successful);

        // Retrying it only delays telling somebody the payload is wrong, and
        // burns the property's ARI budget doing so.
        $this->assertFalse($result->retryable, 'A rejected payload will be rejected again.');
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

    public function test_a_booking_revision_is_read_and_acknowledged(): void
    {
        Http::fake([
            '*/booking_revisions/feed*' => Http::response($this->feedResponse()),
            '*/ack' => Http::response(['meta' => ['message' => 'Success']]),
        ]);

        $bookings = $this->adapter->importReservations($this->account);

        $this->assertCount(1, $bookings);

        $booking = $bookings[0];
        $this->assertSame('9996013801', $booking->externalReservationId);
        $this->assertSame(self::ROOM_TYPE_UUID, $booking->externalListingId);
        $this->assertSame('confirmed', $booking->status);
        // "200.00" in USD, converted through the currency's own exponent.
        $this->assertSame(20000, $booking->totalAmount);
        $this->assertSame('Channex', $booking->guestLastName);
        $this->assertSame('user@channex.io', $booking->guestEmail);

        // Acknowledged, because it was successfully read. Channex re-offers an
        // unacknowledged revision for thirty minutes and then emails a warning.
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/ack')
            && $request->method() === 'POST');
    }

    public function test_a_revision_that_cannot_be_read_is_left_unacknowledged(): void
    {
        // No OTA reference and no room type: importing it would risk a second
        // booking on the next delivery, since the reference is what the
        // platform deduplicates on.
        Http::fake([
            '*/booking_revisions/feed*' => Http::response([
                'data' => [['type' => 'booking_revision', 'id' => 'rev-broken', 'attributes' => [
                    'id' => 'rev-broken',
                    'status' => 'new',
                ]]],
            ]),
            '*/ack' => Http::response(['meta' => ['message' => 'Success']]),
        ]);

        $bookings = $this->adapter->importReservations($this->account);

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
        Http::fake([
            '*/room_types*' => Http::response(['data' => [[
                'type' => 'room_type',
                'id' => 'c0ffee00-0000-4000-8000-000000000001',
                'attributes' => [
                    'id' => 'c0ffee00-0000-4000-8000-000000000001',
                    'property_id' => self::PROPERTY_UUID,
                    'title' => 'Twin Room',
                    'occ_adults' => 2,
                    'currency' => 'USD',
                ],
            ]]]),
            '*/rate_plans*' => Http::response(['data' => [[
                'type' => 'rate_plan',
                'id' => 'c0ffee00-0000-4000-8000-000000000002',
                'attributes' => [
                    'id' => 'c0ffee00-0000-4000-8000-000000000002',
                    'room_type_id' => 'c0ffee00-0000-4000-8000-000000000001',
                    'title' => 'Best Available Rate',
                ],
            ]]]),
        ]);

        $this->app->make(ChannelListingImporter::class)->importFor($this->account);

        $discovered = ChannelListing::query()
            ->where('channel_account_id', $this->account->getKey())
            ->where('external_listing_id', 'c0ffee00-0000-4000-8000-000000000001')
            ->firstOrFail();

        // Availability is a count against a room type; price and restrictions
        // belong to a rate plan inside a property. A push needs all three, and
        // `external_listing_id` holds one — so without these the catalogue
        // would import and then nothing would ever send.
        $this->assertSame(self::PROPERTY_UUID, $discovered->metadata['channex']['property_id'] ?? null);
        $this->assertSame(
            'c0ffee00-0000-4000-8000-000000000002',
            $discovered->metadata['channex']['rate_plan_id'] ?? null,
        );
    }

    // ------------------------------------------------------------------
    // Honesty about what it can do
    // ------------------------------------------------------------------

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
