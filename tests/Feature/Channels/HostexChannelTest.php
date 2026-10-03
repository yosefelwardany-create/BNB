<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Jobs\ProcessChannelWebhookEvent;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Models\ChannelWebhookEvent;
use App\Domain\Channels\Services\ChannelListingImporter;
use App\Domain\Channels\Services\ChannelMessageImporter;
use App\Domain\Integrations\Exceptions\HostexRequestException;
use App\Domain\Integrations\Providers\Channels\HostexChannelAdapter;
use App\Domain\Integrations\Support\HostexClient;
use App\Domain\Listings\Models\Listing;
use App\Domain\Messaging\Events\MessageReceived;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The first channel adapter in this platform that is genuinely live.
 *
 * Every other one simulates, because the major OTAs require a signed partner
 * agreement before their APIs can be used. Hostex already holds those, so a
 * request made here is a real change on a real listing — which is why these
 * tests are mostly about the ways that could go wrong quietly.
 *
 * Three claims are being held:
 *
 * **A 200 is not necessarily a success.** Hostex puts error codes in the body,
 * rate limiting among them. A client written the usual way reads `200`, calls
 * it done, and loses a guest's reply without anybody noticing.
 *
 * **A mapping is never guessed.** It decides which calendar a booking lands on.
 * Hostex properties remain unmapped until an operator selects the exact local
 * property by its source identity.
 *
 * **An unverified webhook is discarded, not recorded.** Writing it down first
 * would let anybody who found the URL fill the table.
 */
class HostexChannelTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    public function test_detailed_conversation_activity_maps_only_the_exact_channel_listing_and_backfills_without_reply_events(): void
    {
        $account = $this->account();
        $property = $this->propertyNamed('Room');
        $listing = Listing::factory()->create(['organization_id' => $account->organization_id, 'property_id' => $property->id]);
        ChannelListing::query()->create([
            'organization_id' => $account->organization_id, 'channel_account_id' => $account->id,
            'property_id' => $property->id, 'listing_id' => $listing->id, 'external_listing_id' => 'hx-room',
            'metadata' => ['hostex_channels' => [['listing_id' => 'ota-room', 'channel_type' => 'airbnb']]],
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/conversations?')) {
                return Http::response(['data' => ['conversations' => [['id' => 'ours'], ['id' => 'other'], ['id' => 'ambiguous']]]]);
            }
            $id = basename(parse_url($request->url(), PHP_URL_PATH));
            $activities = [['listing_id' => $id === 'other' ? 'another-room' : 'ota-room', 'property' => null]];
            if ($id === 'ambiguous') {
                $activities[] = ['listing_id' => 'another-room'];
            }

            return Http::response(['data' => [
                'channel_type' => 'airbnb', 'guest' => ['name' => 'Fixture guest'], 'activities' => $activities,
                'messages' => [
                    ['id' => $id.'-host', 'content' => 'Host reply', 'sender_role' => 'host', 'created_at' => '2026-10-02T12:00:00Z'],
                    ['id' => $id.'-guest', 'content' => 'Guest inquiry', 'sender_role' => 'guest', 'created_at' => '2026-10-01T12:00:00Z'],
                ],
            ]]);
        });
        $adapter = app(HostexChannelAdapter::class);
        $payloads = $adapter->importConversations($account);
        $this->assertCount(2, $payloads);
        $this->assertSame(2, $adapter->unmappedConversationCount);
        $this->assertSame('hx-room', $payloads[0]->attachments['listing_id']);
        $this->assertSame('Guest inquiry', $payloads[0]->body);
        Event::fake([MessageReceived::class]);
        $importer = app(ChannelMessageImporter::class);
        foreach ($payloads as $payload) {
            $importer->record($account, $payload, $payload->attachments['sender_role'] === 'guest', dispatchEvent: false);
        }
        foreach ($payloads as $payload) {
            $this->assertNull($importer->record($account, $payload, dispatchEvent: false));
        }
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('conversations', ['property_id' => $property->id, 'subject' => 'Fixture guest']);
        Event::assertNotDispatched(MessageReceived::class);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_incremental_messages_skip_old_thread_requests_without_skipping_unknown_dates(): void
    {
        $organization = $this->createOrganization();
        $account = ChannelAccount::query()->create([
            'organization_id' => $organization->id, 'channel' => 'hostex', 'name' => 'Fixture',
            'credentials' => ['access_token' => 'test-only'],
        ]);
        Http::fake([
            'api.hostex.io/v3/conversations?*' => Http::response(['data' => ['conversations' => [
                ['id' => 'old', 'last_message_at' => '2026-01-01T00:00:00Z'],
                ['id' => 'new', 'last_message_at' => '2026-10-03T00:00:00Z'],
                ['id' => 'unknown'],
            ]]]),
            'api.hostex.io/v3/conversations/*' => Http::response(['data' => ['messages' => []]]),
        ]);
        app(HostexChannelAdapter::class)->importConversations($account, new \DateTimeImmutable('2026-10-02T00:00:00Z'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/old'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/new'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/unknown'));
    }

    public function test_a_rate_limit_hidden_in_a_200_is_treated_as_a_failure(): void
    {
        // Hostex's actual behaviour: HTTP 200, error in the body.
        Http::fake(['api.hostex.io/*' => Http::response([
            'error_code' => 429,
            'error_msg' => 'Too many requests',
        ], 200, ['Retry-After' => '30'])]);

        $client = new HostexClient('token-123');

        try {
            $client->get('properties');
            $this->fail('A 429 inside a 200 was read as a success.');
        } catch (HostexRequestException $e) {
            $this->assertTrue($e->isRateLimit());
            // Retryable, so the engine backs off rather than surfacing it to a
            // person who can do nothing about it.
            $this->assertTrue($e->retryable);
            $this->assertSame(30, $e->retryAfter);
            $this->assertStringContainsString('Too many requests', $e->getMessage());
        }
    }

    public function test_a_rejected_request_is_not_retried(): void
    {
        Http::fake(['api.hostex.io/*' => Http::response(['error_code' => 401, 'error_msg' => 'Invalid token'], 200)]);

        try {
            (new HostexClient('wrong'))->get('properties');
            $this->fail('An invalid token was read as a success.');
        } catch (HostexRequestException $e) {
            // It will be invalid again in ten seconds. Retrying only delays
            // telling somebody their token is wrong.
            $this->assertFalse($e->retryable);
            $this->assertSame(401, $e->errorCode);
        }
    }

    public function test_a_zero_error_code_is_not_an_error(): void
    {
        // Several APIs spell "no error" as zero. Reading that as a failure
        // would make every successful call look broken.
        Http::fake(['api.hostex.io/*' => Http::response([
            'error_code' => 0,
            'data' => ['properties' => [['id' => '1', 'title' => 'Blue Room']]],
        ], 200)]);

        $this->assertSame(
            [['id' => '1', 'title' => 'Blue Room']],
            (new HostexClient('token'))->get('properties')['properties'],
        );
    }

    public function test_a_two_hundred_error_code_is_hostex_saying_it_worked(): void
    {
        /*
         * The shape a real account returns.
         *
         * Hostex mirrors the HTTP status into the body, so a successful call
         * carries `error_code: 200` and `error_msg: "Done"`. Reading any code as
         * a failure rejected a perfectly good token with the self-refuting
         * message "Hostex answered 200 on GET properties: Done."
         *
         * Found against the live API, which is what `hostex:probe` is for: the
         * field shapes in this client were written defensively from
         * documentation that is unreachable from the build environment.
         */
        Http::fake(['api.hostex.io/*' => Http::response([
            'error_code' => 200,
            'error_msg' => 'Done',
            'data' => ['properties' => [['id' => '1', 'title' => 'Light Green Room']]],
        ], 200)]);

        $this->assertSame(
            [['id' => '1', 'title' => 'Light Green Room']],
            (new HostexClient('token'))->get('properties')['properties'],
        );
    }

    public function test_a_real_error_inside_a_two_hundred_is_still_an_error(): void
    {
        // The rule that makes this client worth having: a 200 carrying 429 is a
        // rate limit, and widening "success" to 2xx must not blunt that.
        Http::fake(['api.hostex.io/*' => Http::response([
            'error_code' => 429,
            'error_msg' => 'Too many requests.',
        ], 200)]);

        try {
            (new HostexClient('token'))->get('properties');
            $this->fail('A rate limit inside a 200 must still be a failure.');
        } catch (HostexRequestException $e) {
            $this->assertSame(429, $e->errorCode);
            $this->assertTrue($e->retryable);
        }
    }

    public function test_the_token_travels_in_hostex_own_header(): void
    {
        Http::fake(['api.hostex.io/*' => Http::response(['data' => []], 200)]);

        (new HostexClient('token-123'))->get('properties');

        Http::assertSent(fn ($request): bool => $request->hasHeader('Hostex-Access-Token', 'token-123'));
    }

    public function test_hostex_listings_require_an_explicit_mapping_even_when_the_name_matches(): void
    {
        $account = $this->account();
        $this->propertyNamed('Blue Room');
        $this->propertyNamed('Yellow Room');

        // One page: the importer stops as soon as a page comes back short, so
        // a single response is the whole conversation.
        Http::fake(['api.hostex.io/*' => Http::response(['data' => ['properties' => [
            ['id' => 'hx-1', 'title' => 'Blue Room', 'city' => 'Toronto'],
            ['id' => 'hx-2', 'title' => 'Something Nobody Has Here', 'city' => 'Toronto'],
        ]]], 200)]);

        $counts = $this->app->make(ChannelListingImporter::class)->importFor($account);

        $this->assertSame(2, $counts['discovered']);
        $this->assertSame(0, $counts['matched']);
        $this->assertSame(2, $counts['unmapped']);

        // The one it recognised is linked...
        $matched = ChannelListing::query()->where('external_listing_id', 'hx-1')->firstOrFail();
        $this->assertNull($matched->listing_id);

        // ...and the one it did not is imported and visibly waiting, rather
        // than attached to whichever property sorted first.
        $unmatched = ChannelListing::query()->where('external_listing_id', 'hx-2')->firstOrFail();
        $this->assertNull($unmatched->listing_id);
        $this->assertSame('Something Nobody Has Here', $unmatched->external_name);
    }

    public function test_two_listings_with_the_same_name_are_both_left_for_a_person(): void
    {
        $account = $this->account();
        $this->propertyNamed('Blue Room');
        $this->propertyNamed('Blue Room');

        Http::fake(['api.hostex.io/*' => Http::response(
            ['data' => ['properties' => [['id' => 'hx-1', 'title' => 'Blue Room']]]],
            200,
        )]);

        $counts = $this->app->make(ChannelListingImporter::class)->importFor($account);

        // Not a near miss to be broken by ordering. Picking either would be a
        // coin toss deciding where somebody's booking lands.
        $this->assertSame(0, $counts['matched']);
        $this->assertSame(1, $counts['unmapped']);
    }

    public function test_re_importing_never_moves_a_mapping_somebody_made(): void
    {
        $account = $this->account();
        $blue = $this->propertyNamed('Blue Room');

        /*
         * One stub whose answer changes, rather than two fakes.
         *
         * `Http::fake()` merges its stubs and the first match wins, so a second
         * call with the same URL pattern never takes effect — which is its own
         * small trap and cost a confusing failure here.
         */
        $title = 'Renamed On The Channel';

        Http::fake(['api.hostex.io/*' => function () use (&$title) {
            return Http::response(['data' => ['properties' => [
                ['id' => 'hx-1', 'title' => $title],
            ]]], 200);
        }]);

        $this->app->make(ChannelListingImporter::class)->importFor($account);

        $mapping = ChannelListing::query()->where('external_listing_id', 'hx-1')->firstOrFail();
        $listing = Listing::query()->where('property_id', $blue->getKey())->firstOrFail();

        // Somebody maps it by hand.
        $mapping->forceFill(['listing_id' => $listing->getKey(), 'property_id' => $blue->getKey()])->save();

        $title = 'Renamed Again';

        $this->app->make(ChannelListingImporter::class)->importFor($account);

        $mapping->refresh();

        // A human decision outranks a string comparison, and the name is still
        // refreshed so the row stays recognisable.
        $this->assertSame($listing->getKey(), $mapping->listing_id);
        $this->assertSame('Renamed Again', $mapping->external_name);
    }

    public function test_a_webhook_without_the_secret_is_discarded_and_not_recorded(): void
    {
        $account = $this->account();

        $this->postJson("/webhooks/channels/{$account->getKey()}", ['event' => 'message_created'])
            ->assertStatus(404);

        $this->postJson(
            "/webhooks/channels/{$account->getKey()}",
            ['event' => 'message_created'],
            ['Hostex-Webhook-Secret-Token' => 'not-the-secret'],
        )->assertStatus(404);

        // Nothing written. Recording first would let anybody who found the URL
        // fill the table.
        $this->assertSame(0, ChannelWebhookEvent::query()->withoutGlobalScope('organization')->count());
    }

    public function test_a_verified_webhook_is_recorded_and_queued(): void
    {
        Queue::fake();
        $account = $this->account();

        $this->postJson(
            "/webhooks/channels/{$account->getKey()}",
            ['event' => 'reservation_created', 'id' => 'evt-1', 'data' => ['reservation_code' => 'HX1']],
            ['Hostex-Webhook-Secret-Token' => 'the-secret'],
        )->assertStatus(202);

        $this->assertDatabaseHas('channel_webhook_events', [
            'provider_event_id' => 'evt-1',
            'type' => 'reservation_created',
            'status' => ChannelWebhookEvent::PENDING,
        ]);

        Queue::assertPushed(ProcessChannelWebhookEvent::class);
    }

    public function test_the_same_event_delivered_twice_is_accepted_once(): void
    {
        Queue::fake();
        $account = $this->account();

        $send = fn () => $this->postJson(
            "/webhooks/channels/{$account->getKey()}",
            ['event' => 'message_created', 'id' => 'evt-9', 'data' => []],
            ['Hostex-Webhook-Secret-Token' => 'the-secret'],
        );

        $send()->assertStatus(202);

        // A redelivery is the sender doing its job, so it is answered 200 and
        // not treated as an error — telling it otherwise invites the retry
        // storm this exists to absorb.
        $send()->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, ChannelWebhookEvent::query()->withoutGlobalScope('organization')->count());
        Queue::assertPushed(ProcessChannelWebhookEvent::class, 1);
    }

    public function test_the_adapter_reports_what_it_can_actually_do(): void
    {
        $adapter = $this->app->make(HostexChannelAdapter::class);

        // Live, and says so — unlike every other OTA adapter here.
        $this->assertTrue($adapter->isLive());
        $this->assertNull($adapter->simulationReason());

        $this->assertTrue($adapter->supports(HostexChannelAdapter::CAPABILITY_MESSAGING));
        $this->assertTrue($adapter->supports(HostexChannelAdapter::CAPABILITY_IMPORT_RESERVATIONS));

        // Not claimed: a listing is created on the OTA and in Hostex, and a
        // publish button that cannot publish is worse than none.
        $this->assertFalse($adapter->supports(HostexChannelAdapter::CAPABILITY_PUBLISH_LISTINGS));
    }

    public function test_a_reservation_is_read_into_the_platform_shape(): void
    {
        $payload = $this->app->make(HostexChannelAdapter::class)->reservation([
            'reservation_code' => 'hostex-order-1',
            'stay_code' => 'hostex-stay-1',
            'channel_id' => 'HMX5F8DWAE',
            'property_id' => 'hx-1',
            'status' => 'accepted',
            'check_in_date' => '2026-11-02',
            'check_out_date' => '2026-11-06',
            'number_of_adults' => 2,
            'guest_name' => 'Ana Costa', 'guest_email' => 'ana@example.test',
            'rates' => ['rate' => ['currency' => 'CAD', 'amount' => 412.5]],
        ]);

        $this->assertNotNull($payload);
        $this->assertSame('confirmed', $payload->status);
        // Minor units, converted in one place. Doing it per call site is how a
        // currency ends up a hundred times out in one report and right in every
        // other.
        $this->assertSame(41250, $payload->totalAmount);
        $this->assertSame('HMX5F8DWAE', $payload->confirmationCode);
        $this->assertSame('hostex-stay-1', $payload->externalReservationId);
        $this->assertSame('Ana', $payload->guestFirstName);
        $this->assertSame('Costa', $payload->guestLastName);
    }

    public function test_an_unrecognised_status_holds_rather_than_books(): void
    {
        $payload = $this->app->make(HostexChannelAdapter::class)->reservation([
            'reservation_code' => 'HX2',
            'property_id' => 'hx-1',
            'status' => 'something_new_hostex_added',
            'check_in_date' => '2026-11-02',
            'check_out_date' => '2026-11-06',
        ]);

        // Guessing `confirmed` would put a stay on a calendar on the strength
        // of a string nobody has seen before.
        $this->assertSame('pending', $payload?->status);
    }

    private function account(): ChannelAccount
    {
        $this->organization = $this->createOrganization();
        $this->actingAsUser($this->createUser($this->organization), $this->organization);

        return ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'hostex',
            'name' => 'Hostex',
            'credentials' => ['access_token' => 'token-123'],
            'webhook_secret' => 'the-secret',
            'status' => ChannelAccount::STATUS_CONNECTED,
        ]);
    }

    private function propertyNamed(string $name): Property
    {
        return $this->app->make(PropertyService::class)->create([
            'name' => $name,
            'property_type' => 'apartment',
            'city' => 'Toronto',
            'country_code' => 'CA',
            'max_occupancy' => 2,
            'base_rate' => 5800,
        ]);
    }
}
