<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Listings\Models\Listing;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConversationService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Logging messages that travelled somewhere else.
 *
 * The workflow this exists for: a guest writes on Airbnb, a person pastes it
 * here, the property's agent drafts a reply, the person copies it back. The
 * claims worth protecting are that the thread is accurate afterwards — response
 * times, ordering, who wrote what — and that **nothing is ever sent**. A thread
 * showing "sent" for a message this platform only heard about would be the same
 * lie as a simulated channel reporting a successful push.
 */
class ManualMessageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private Conversation $conversation;

    private ConversationService $conversations;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->conversations = $this->app->make(ConversationService::class);
        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);
        $this->admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);
        $this->conversation = $this->conversations->forReservation($this->booking());
    }

    public function test_a_message_the_guest_sent_elsewhere_is_logged_onto_the_thread(): void
    {
        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson($this->url('received'), [
                'body' => 'Hi! Is there parking near the flat?',
                'transport' => 'airbnb',
            ])
            ->assertCreated();

        $response->assertJsonPath('data.direction', Message::INBOUND);
        $response->assertJsonPath('meta.was_sent_by_us', false);

        $message = Message::query()->latest('created_at')->firstOrFail();

        $this->assertSame('airbnb', $message->transport);
        $this->assertSame('guest', $message->author_type);

        // It counts as a guest waiting, which is the whole point of logging it.
        $this->assertSame(1, $this->conversation->fresh()->unread_count);
        $this->assertNotNull($this->conversation->fresh()->last_inbound_at);
    }

    public function test_an_unnamed_transport_is_recorded_as_manual_rather_than_guessed(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->postJson($this->url('received'), ['body' => 'Hello?'])
            ->assertCreated();

        // Not 'email': dressing an unknown origin up as one would put a false
        // fact in the record of a conversation somebody may later dispute.
        $this->assertSame('manual', Message::query()->latest('created_at')->firstOrFail()->transport);
    }

    public function test_a_reply_carried_by_hand_is_recorded_without_being_sent(): void
    {
        $this->conversations->recordInbound($this->conversation, ['body' => 'Is there parking?']);

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson($this->url('delivered'), [
                'body' => 'There is metered parking on Rua da Prata, two minutes away.',
                'transport' => 'airbnb',
                'is_ai_generated' => true,
            ])
            ->assertCreated();

        $response->assertJsonPath('data.direction', Message::OUTBOUND);
        $response->assertJsonPath('meta.delivered_by_hand', true);
        $response->assertJsonPath('meta.was_sent_by_us', false);

        $message = Message::query()->where('direction', Message::OUTBOUND)->firstOrFail();

        $this->assertSame('delivered', $message->status);
        $this->assertSame('airbnb', $message->transport);
        // Still attributable: a manager can tell a model drafted this even
        // though it left through a person's hands.
        $this->assertTrue($message->is_ai_generated);
        $this->assertSame($this->admin->getKey(), $message->approved_by_id);

        // The load-bearing assertion. Nothing was dispatched anywhere.
        Mail::assertNothingSent();
    }

    public function test_a_reply_carried_by_hand_still_stops_the_response_clock(): void
    {
        $this->conversations->recordInbound($this->conversation, ['body' => 'Is there parking?']);

        $this->assertNull($this->conversation->fresh()->first_response_at);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson($this->url('delivered'), ['body' => 'Yes, on Rua da Prata.'])
            ->assertCreated();

        $thread = $this->conversation->fresh();

        // Without this, every response-time report would count a guest who was
        // answered in four minutes as never answered at all.
        $this->assertNotNull($thread->first_response_at);
        $this->assertNotNull($thread->first_response_minutes);
        $this->assertSame(Message::OUTBOUND, $thread->last_message_direction);
    }

    public function test_a_message_can_be_logged_with_the_time_it_actually_arrived(): void
    {
        $arrived = CarbonImmutable::now()->subHours(3);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson($this->url('received'), [
                'body' => 'We are running late.',
                'received_at' => $arrived->toIso8601String(),
            ])
            ->assertCreated();

        // Pasted in three hours late, the guest should not read as having
        // written just now — the thread is evidence, and the times are part of it.
        $this->assertSame(
            $arrived->toDateTimeString(),
            Message::query()->latest('created_at')->firstOrFail()->created_at?->toDateTimeString(),
        );
    }

    public function test_logging_what_a_guest_said_needs_only_permission_to_read_the_inbox(): void
    {
        $agent = $this->createUser($this->organization, [RoleRegistry::RESERVATIONS_AGENT]);

        $this->actingAsUser($agent, $this->organization)
            ->postJson($this->url('received'), ['body' => 'Hello!'])
            ->assertCreated();
    }

    public function test_claiming_a_guest_was_answered_needs_permission_to_send(): void
    {
        // A cleaner may not contact a guest in the company's name, and must not
        // be able to assert in the record that somebody did.
        $cleaner = $this->createUser($this->organization, [RoleRegistry::CLEANER]);

        $this->actingAsUser($cleaner, $this->organization)
            ->postJson($this->url('delivered'), ['body' => 'All sorted!'])
            ->assertForbidden();
    }

    public function test_an_empty_message_is_refused(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->postJson($this->url('received'), ['body' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    private function url(string $action): string
    {
        return sprintf('/api/v1/conversations/%s/%s', $this->conversation->getKey(), $action);
    }

    private function booking(): Reservation
    {
        $property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'max_occupancy' => 4,
        ]);

        $listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
            'base_rate' => 10000,
            'max_occupancy' => 4,
        ]);

        return $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $listing,
            checkIn: CarbonImmutable::now($property->timezone)->startOfDay()->addDays(5),
            checkOut: CarbonImmutable::now($property->timezone)->startOfDay()->addDays(8),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Marta',
                'last_name' => 'Silva',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
            bookedAt: CarbonImmutable::now(),
        ));
    }
}
