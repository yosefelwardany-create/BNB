<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelPuller;
use App\Domain\Integrations\DataObjects\ChannelMessagePayload;
use App\Domain\Integrations\Providers\Channels\ChannexChannelAdapter;
use App\Domain\Listings\Models\Listing;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConversationService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guest messages from Booking.com, Airbnb and Expedia, through Channex.
 *
 * Channex relays them as message threads once its Messages app is installed
 * on the property. A thread lands in the inbox against the booking it belongs
 * to, and a reply written here goes back into the same thread, so the guest
 * reads it where they wrote. The shapes below are the ones Channex documents;
 * staging answers 403 for a property without the app.
 */
class ChannexMessagingTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY = '716305c4-561a-4561-a187-7f5b8aeb5920';

    private const ROOM_TYPE = '994d1375-dbbd-4072-8724-b2ab32ce781b';

    private const BOOKING = '4d8240fd-d709-454b-a866-08bca2a5a909';

    private const THREAD = '20d1b08c-190e-4068-a77d-4a909a21835d';

    private Organization $organization;

    private ChannelAccount $account;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'GBP']);

        $property = Property::factory()->active()->create(['organization_id' => $this->organization->getKey(), 'currency' => 'GBP']);
        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
            'currency' => 'GBP',
        ]);

        $this->account = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'channex',
            'name' => 'Channex — staging',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'credentials' => ['access_token' => 'test-key'],
            'import_reservations' => true,
            'sync_messages' => true,
        ]);

        $this->link(self::ROOM_TYPE, $this->listing);

        Http::preventStrayRequests();
    }

    public function test_a_guests_messages_arrive_in_the_inbox_on_their_booking(): void
    {
        $this->fakeChannex();

        $outcome = $this->app->make(ChannelPuller::class)->pull($this->account);

        $reservation = Reservation::query()->where('external_reservation_id', 'BDC-1')->firstOrFail();
        $conversation = Conversation::query()->where('external_thread_id', self::THREAD)->firstOrFail();

        // On the booking, found through the OTA's code Channex's booking
        // carries — the same code the reservation was imported under.
        $this->assertSame($reservation->getKey(), $conversation->reservation_id);
        $this->assertSame('channex', $conversation->channel);

        $messages = $conversation->messages()->orderBy('sent_at')->get();

        $this->assertSame(['Can we check in early?', 'Yes, from noon.'], $messages->pluck('body')->all());
        $this->assertSame(Message::INBOUND, $messages[0]->direction);
        // The property's side, typed into Booking.com by somebody, is the
        // host's — not the guest's, and not claimed as sent from here.
        $this->assertSame(Message::OUTBOUND, $messages[1]->direction);

        $this->assertSame(2, $outcome['messages']['recorded'] ?? null);
        $this->assertSame('booking.com', $conversation->fresh()->metadata['source_channel'] ?? null);
    }

    public function test_pulling_again_brings_nothing_twice_and_reads_the_booking_once(): void
    {
        $this->fakeChannex();

        $this->app->make(ChannelPuller::class)->pull($this->account);
        $this->app->make(ChannelPuller::class)->pull($this->account->fresh());

        $this->assertSame(2, Message::query()->count());
        // Once the conversation is here it is found by its thread id; the
        // booking is not asked for again every five minutes.
        $this->assertSame(1, $this->sent(fn (Request $request): bool => $this->askedForBooking($request)));
        // And never through the bookings endpoints, which Channex's
        // certification refuses.
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/bookings'));
    }

    public function test_a_reply_goes_back_into_the_channex_thread(): void
    {
        $this->fakeChannex();
        $this->app->make(ChannelPuller::class)->pull($this->account);
        $conversation = Conversation::query()->where('external_thread_id', self::THREAD)->firstOrFail();

        $message = $this->app->make(ConversationService::class)->send($conversation, ['body' => 'See you at noon.'])->fresh();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/message_threads/'.self::THREAD.'/messages')
            && $request->data() === ['message' => ['message' => 'See you at noon.']]);

        $this->assertSame('channel', $message->transport);
        $this->assertFalse($message->metadata['delivery']['simulated'] ?? true, 'Channex is live: the guest gets it.');
        // Channex's id for it, so the copy on the next pull is known as ours.
        $this->assertSame('34849183-7c36-4ac4-9103-cfaeecbc2cc8', $message->external_message_id);
    }

    public function test_without_the_messages_app_the_pull_says_so_and_still_completes(): void
    {
        $this->fakeChannex(threads: Http::response(['errors' => ['code' => 'forbidden', 'title' => 'Forbidden']], 403));

        $outcome = $this->app->make(ChannelPuller::class)->pull($this->account);

        $this->assertStringContainsString('Messages app', (string) ($outcome['messages']['skipped'] ?? ''));
        // A setting in Channex, not a fault: the bookings and the rest of the
        // pull stand.
        $this->assertSame('completed', $outcome['status']);
    }

    public function test_a_reply_without_the_messages_app_says_what_to_install(): void
    {
        Http::fake(['*/message_threads/*' => Http::response(['errors' => ['code' => 'forbidden', 'title' => 'Forbidden']], 403)]);

        $mapping = ChannelListing::query()->where('external_listing_id', self::ROOM_TYPE)->firstOrFail();

        $result = (new ChannexChannelAdapter)->sendMessage($mapping, new ChannelMessagePayload(
            body: 'See you at noon.',
            externalThreadId: self::THREAD,
        ));

        $this->assertSame('messages_app_missing', $result->errorCode);
        $this->assertFalse($result->retryable);
        $this->assertStringContainsString('Messages app', (string) $result->errorMessage);
    }

    public function test_an_airbnb_inquiry_reaches_the_room_type_it_asks_about(): void
    {
        // An inquiry has no booking yet. Channex's system line about it names
        // the room type, and is not itself a message anybody wrote.
        $this->fakeChannex(
            threads: Http::response($this->page([$this->thread(booking: false, provider: 'AirBNB', title: 'Andrew')])),
            messages: Http::response($this->page([
                $this->message('m-0', 'inquiry', 'system', '2026-11-01T07:01:49.000000', ['booking_details' => ['room_type_id' => self::ROOM_TYPE]]),
                $this->message('m-1', 'Is the flat free next weekend?', 'guest', '2026-11-01T07:02:00.000000'),
            ])),
            revisions: [],
        );

        $this->app->make(ChannelPuller::class)->pull($this->account);

        $conversation = Conversation::query()->where('external_thread_id', self::THREAD)->firstOrFail();

        $this->assertSame($this->listing->getKey(), $conversation->listing_id);
        $this->assertNull($conversation->reservation_id);
        $this->assertSame(['Is the flat free next weekend?'], $conversation->messages()->pluck('body')->all());
    }

    public function test_a_thread_that_could_be_any_of_several_room_types_is_left_out(): void
    {
        $other = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->listing->property_id,
            'currency' => 'GBP',
        ]);
        $this->link('aaaa0000-0000-4000-8000-000000000002', $other);

        $this->fakeChannex(
            threads: Http::response($this->page([$this->thread(booking: false)])),
            revisions: [],
        );

        $outcome = $this->app->make(ChannelPuller::class)->pull($this->account);

        // Never a guess between two rooms.
        $this->assertSame(0, Conversation::query()->count());
        $this->assertSame(1, $outcome['messages']['unmapped_threads'] ?? null);
    }

    // ------------------------------------------------------------------

    private function link(string $roomType, Listing $listing): void
    {
        ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $this->account->getKey(),
            'listing_id' => $listing->getKey(),
            'property_id' => $listing->property_id,
            'external_listing_id' => $roomType,
            'is_active' => true,
            'metadata' => ['channex' => ['property_id' => self::PROPERTY]],
        ]);
    }

    /**
     * Whether a request asks Channex's revisions list for the thread's booking.
     */
    private function askedForBooking(Request $request): bool
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_contains($request->url(), '/booking_revisions?')
            && ($query['filter']['booking_id'] ?? null) === self::BOOKING;
    }

    /**
     * How many recorded requests match.
     */
    private function sent(callable $matches): int
    {
        return Http::recorded()->filter(fn (array $pair): bool => $matches($pair[0]))->count();
    }

    /**
     * A pull's worth of Channex: an empty catalogue, the booking the thread
     * belongs to arriving through the feed, the thread and its messages.
     *
     * @param  list<array<string, mixed>>|null  $revisions
     */
    private function fakeChannex(mixed $threads = null, mixed $messages = null, ?array $revisions = null): void
    {
        $empty = Http::response($this->page([]));

        $revisions ??= [[
            'type' => 'booking_revision',
            'id' => 'rev-1',
            'attributes' => [
                'id' => 'rev-1',
                'booking_id' => self::BOOKING,
                'property_id' => self::PROPERTY,
                'ota_reservation_code' => 'BDC-1',
                'status' => 'new',
                'arrival_date' => now()->addDays(10)->toDateString(),
                'departure_date' => now()->addDays(12)->toDateString(),
                'amount' => '200.00',
                'currency' => 'GBP',
                'customer' => ['name' => 'Roxy', 'surname' => 'Maldonado'],
                'rooms' => [['room_type_id' => self::ROOM_TYPE]],
            ],
        ]];

        $messages ??= Http::response($this->page([
            $this->message('m-1', 'Can we check in early?', 'guest', now()->subHours(2)->format('Y-m-d\TH:i:s.u')),
            $this->message('m-2', 'Yes, from noon.', 'property', now()->subHour()->format('Y-m-d\TH:i:s.u')),
        ]));

        Http::fake([
            '*/message_threads/'.self::THREAD.'/messages*' => fn (Request $request) => $request->method() === 'POST'
                ? Http::response(['data' => [
                    'type' => 'message',
                    'id' => '34849183-7c36-4ac4-9103-cfaeecbc2cc8',
                    'attributes' => ['message' => 'See you at noon.', 'sender' => 'property', 'attachments' => []],
                ]])
                : $messages,
            '*/message_threads*' => $threads ?? Http::response($this->page([$this->thread()])),
            // The revisions list: asked for one booking's newest revision when
            // a thread is new here, and for everything on the first pull.
            '*/api/v1/booking_revisions?*' => fn (Request $request) => $this->askedForBooking($request)
                ? Http::response($this->page([[
                    'type' => 'booking_revision',
                    'id' => 'rev-1',
                    'attributes' => [
                        'id' => 'rev-1',
                        'booking_id' => self::BOOKING,
                        'ota_reservation_code' => 'BDC-1',
                        'rooms' => [['room_type_id' => self::ROOM_TYPE]],
                    ],
                ]]))
                : $empty,
            '*/booking_revisions/feed*' => Http::response($this->page($revisions)),
            '*/ack' => Http::response(['meta' => ['message' => 'Success']]),
            '*/properties*' => $empty,
            '*/room_types*' => $empty,
            '*/rate_plans*' => $empty,
            '*/restrictions*' => Http::response(['data' => []]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function thread(bool $booking = true, string $provider = 'BookingCom', string $title = 'Maldonado Roxy'): array
    {
        return [
            'type' => 'message_thread',
            'id' => self::THREAD,
            'attributes' => [
                'title' => $title,
                'is_closed' => false,
                'provider' => $provider,
                'message_count' => 2,
                'last_message_received_at' => now()->subHour()->format('Y-m-d\TH:i:s.u'),
            ],
            'relationships' => array_filter([
                'booking' => $booking ? ['data' => ['id' => self::BOOKING, 'type' => 'booking']] : null,
                'property' => ['data' => ['id' => self::PROPERTY, 'type' => 'property']],
                'channel' => ['data' => ['id' => '351f145d-cb8c-4df7-9f12-2f6854991b91', 'type' => 'channel']],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>
     */
    private function message(string $id, string $text, string $sender, string $at, ?array $meta = null): array
    {
        return [
            'type' => 'message',
            'id' => $id,
            'attributes' => array_filter([
                'message' => $text,
                'attachments' => [],
                'sender' => $sender,
                'meta' => $meta,
                'inserted_at' => $at,
                'updated_at' => $at,
            ], static fn (mixed $value): bool => $value !== null),
            'relationships' => ['message_thread' => ['data' => ['id' => self::THREAD, 'type' => 'message_thread']]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function page(array $rows): array
    {
        return ['data' => $rows, 'meta' => ['total' => count($rows), 'limit' => 100, 'page' => 1]];
    }
}
