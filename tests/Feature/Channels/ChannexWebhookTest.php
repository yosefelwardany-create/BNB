<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Jobs\PullChannelBookings;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Models\ChannelWebhookEvent;
use App\Domain\Channels\Services\ChannexWebhooks;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Channex tells Habitat the moment a booking arrives, and Habitat reads it
 * then, rather than at the next five-minute import: a night sold on Airbnb
 * closes on the booking website within seconds.
 *
 * The webhook is only a nudge. Habitat reads the booking from Channex's own
 * feed, as the five-minute import does, so a message that arrives twice, late
 * or out of order changes nothing. It is believed only with the secret header
 * Habitat registered it with.
 */
class ChannexWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY = '716305c4-561a-4561-a187-7f5b8aeb5920';

    private const OTHER_PROPERTY = '2f6c1b9e-3d4a-4c5b-9e8f-7a6b5c4d3e2f';

    private const ROOM_TYPE = '994d1375-dbbd-4072-8724-b2ab32ce781b';

    private const SECRET = 'a-secret-only-channex-and-habitat-know-0123456789';

    private Organization $organization;

    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'USD']);

        $this->account = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'channex',
            'name' => 'Channex — staging',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'credentials' => ['access_token' => 'test-key'],
            'import_reservations' => true,
            'webhook_secret' => self::SECRET,
        ]);

        $property = Property::factory()->active()->create(['organization_id' => $this->organization->getKey(), 'currency' => 'USD']);
        $listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
            'currency' => 'USD',
        ]);
        ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $this->account->getKey(),
            'listing_id' => $listing->getKey(),
            'property_id' => $property->getKey(),
            'external_listing_id' => self::ROOM_TYPE,
            'is_active' => true,
            'metadata' => ['channex' => ['property_id' => self::PROPERTY]],
        ]);

        $this->app->make(TenantContext::class)->clear();
    }

    public function test_a_booking_webhook_reads_the_new_booking_at_once(): void
    {
        $this->fakeChannexFeed();

        $this->webhook('booking_new')->assertStatus(202);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/booking_revisions/feed'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/booking_revisions/rev-1/ack'));
        $this->assertSame(1, $this->withoutScope(fn () => Reservation::query()->count()));
        $this->assertSame(ChannelWebhookEvent::PROCESSED, $this->withoutScope(fn () => ChannelWebhookEvent::query()->sole()->status));
    }

    public function test_a_webhook_without_the_secret_is_refused(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->webhook('booking_new', secret: null)->assertNotFound();
        $this->webhook('booking_new', secret: 'not-the-secret')->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(0, $this->withoutScope(fn () => ChannelWebhookEvent::query()->count()));
    }

    public function test_a_webhook_sent_twice_is_acted_on_once(): void
    {
        $this->fakeChannexFeed();

        $this->webhook('booking_new')->assertStatus(202);
        $this->webhook('booking_new')->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertCount(1, Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/booking_revisions/feed')));
    }

    public function test_an_event_that_is_not_about_a_booking_is_noted_and_left(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->webhook('sync_warning')->assertStatus(202);

        Http::assertNothingSent();
        $this->assertSame(ChannelWebhookEvent::IGNORED, $this->withoutScope(fn () => ChannelWebhookEvent::query()->sole()->status));
    }

    public function test_a_booking_webhook_during_an_import_waits_its_turn(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        // The five-minute import holds the connection's lease.
        DB::table('cache_locks')->insert([
            'key' => 'channel-pull:'.$this->organization->getKey().':'.$this->account->getKey(),
            'owner' => 'the-five-minute-import',
            'expiration' => now()->addMinutes(5)->timestamp,
        ]);

        $job = (new PullChannelBookings($this->account->getKey()))->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);

        $job->assertReleased(30);
        Http::assertNothingSent();
    }

    public function test_habitat_registers_a_booking_webhook_for_each_linked_property(): void
    {
        config(['app.url' => 'https://habitat.test']);
        $this->link(self::OTHER_PROPERTY);
        $callback = 'https://habitat.test/webhooks/channels/'.$this->account->getKey();

        Http::preventStrayRequests();
        Http::fake([
            '*/api/v1/webhooks?*' => Http::response(['data' => [[
                'type' => 'webhook',
                'id' => 'wh-1',
                'attributes' => ['callback_url' => $callback, 'property_id' => self::PROPERTY, 'event_mask' => 'booking', 'is_active' => false],
            ]], 'meta' => ['total' => 1]]),
            '*/api/v1/webhooks/wh-1' => Http::response(['data' => ['id' => 'wh-1']]),
            '*/api/v1/webhooks' => Http::response(['data' => ['id' => 'wh-2']], 201),
        ]);

        $outcome = $this->app->make(ChannexWebhooks::class)->ensure($this->account->fresh());

        $this->assertSame(['created' => 1, 'updated' => 1], $outcome);

        // The one already there is brought up to date; the missing one is made.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/webhooks/wh-1')
            && $request['webhook']['property_id'] === self::PROPERTY
            && $request['webhook']['is_active'] === true);
        Http::assertSent(function (Request $request) use ($callback): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/webhooks')) {
                return false;
            }

            $webhook = $request['webhook'];

            return $webhook['property_id'] === self::OTHER_PROPERTY
                && $webhook['callback_url'] === $callback
                && $webhook['event_mask'] === 'booking'
                && $webhook['is_active'] === true
                && $webhook['send_data'] === true
                && $webhook['headers'] === ['X-Habitat-Webhook-Secret' => self::SECRET];
        });

        // Nothing changed since: nothing is sent again.
        $before = count(Http::recorded());
        $this->assertSame(['unchanged' => 2], $this->app->make(ChannexWebhooks::class)->ensure($this->account->fresh()));
        $this->assertCount($before, Http::recorded());
    }

    public function test_a_connection_without_a_secret_gets_one_when_its_webhook_is_registered(): void
    {
        config(['app.url' => 'https://habitat.test']);
        $this->account->forceFill(['webhook_secret' => null])->save();

        Http::preventStrayRequests();
        Http::fake([
            '*/api/v1/webhooks?*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/api/v1/webhooks' => Http::response(['data' => ['id' => 'wh-1']], 201),
        ]);

        $this->app->make(ChannexWebhooks::class)->ensure($this->account->fresh());

        $secret = $this->account->fresh()->webhook_secret;
        $this->assertIsString($secret);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['webhook']['headers'] === ['X-Habitat-Webhook-Secret' => $secret]);
    }

    public function test_no_webhook_is_registered_while_habitat_is_not_on_https(): void
    {
        config(['app.url' => 'http://localhost']);

        Http::preventStrayRequests();
        Http::fake();

        $outcome = $this->app->make(ChannexWebhooks::class)->ensure($this->account->fresh());

        $this->assertArrayHasKey('skipped', $outcome);
        Http::assertNothingSent();
    }

    public function test_the_automatic_import_keeps_the_webhook_registered(): void
    {
        config(['app.url' => 'https://habitat.test']);

        Http::preventStrayRequests();
        Http::fake([
            '*/api/v1/webhooks?*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/api/v1/webhooks' => Http::response(['data' => ['id' => 'wh-1']], 201),
            '*/properties*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/room_types*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/rate_plans*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/api/v1/booking_revisions?*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/booking_revisions/feed*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
        ]);

        $this->artisan('channels:watch', ['--once' => true])->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/webhooks'));
    }

    private function link(string $channexProperty): void
    {
        $property = Property::factory()->active()->create(['organization_id' => $this->organization->getKey(), 'currency' => 'USD']);
        $listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
            'currency' => 'USD',
        ]);
        ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $this->account->getKey(),
            'listing_id' => $listing->getKey(),
            'property_id' => $property->getKey(),
            'external_listing_id' => 'room-type-'.$channexProperty,
            'is_active' => true,
            'metadata' => ['channex' => ['property_id' => $channexProperty]],
        ]);
        $this->app->make(TenantContext::class)->clear();
    }

    private function fakeChannexFeed(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/api/v1/booking_revisions?*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/booking_revisions/feed*' => Http::response(['data' => [[
                'type' => 'booking_revision',
                'id' => 'rev-1',
                'attributes' => [
                    'id' => 'rev-1',
                    'booking_id' => 'bk-1',
                    'property_id' => self::PROPERTY,
                    'ota_reservation_code' => 'BDC-1',
                    'status' => 'new',
                    'arrival_date' => now()->addDays(10)->toDateString(),
                    'departure_date' => now()->addDays(12)->toDateString(),
                    'amount' => '200.00',
                    'currency' => 'USD',
                    'rooms' => [['room_type_id' => self::ROOM_TYPE]],
                ],
            ]], 'meta' => ['total' => 1]]),
            '*/ack' => Http::response(['meta' => ['message' => 'Success']]),
        ]);
    }

    /**
     * As Channex sends it: the event, the property, and the booking it is
     * about, with the header Habitat registered.
     */
    private function webhook(string $event, ?string $secret = self::SECRET): TestResponse
    {
        $body = (string) json_encode([
            'event' => $event,
            'property_id' => self::PROPERTY,
            'user_id' => 'user-1',
            'timestamp' => '2026-11-05T10:15:00.123456Z',
            'payload' => ['booking_id' => 'bk-1', 'revision_id' => 'rev-1', 'property_id' => self::PROPERTY],
        ]);

        return $this->call('POST', '/webhooks/channels/'.$this->account->getKey(), [], [], [], array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HABITAT_WEBHOOK_SECRET' => $secret,
        ]), $body);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function withoutScope(callable $read): mixed
    {
        return $this->app->make(TenantContext::class)->withoutScope($read);
    }
}
