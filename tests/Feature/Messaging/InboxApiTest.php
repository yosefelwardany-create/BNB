<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Listings\Models\Listing;
use App\Domain\Messaging\Models\MessageTemplate;
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
use Tests\TestCase;

/**
 * The inbox and template editor over HTTP.
 */
class InboxApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'max_occupancy' => 4,
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);

        $this->agent = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);
    }

    public function test_the_inbox_puts_the_longest_waiting_guest_first(): void
    {
        $conversations = $this->app->make(ConversationService::class);

        $recent = $conversations->forReservation($this->book(20));
        $waiting = $conversations->forReservation($this->book(40));

        $conversations->recordInbound($recent, ['body' => 'Just now']);
        $conversations->recordInbound($waiting, ['body' => 'Three days ago']);

        $waiting->forceFill(['last_inbound_at' => now()->subDays(3)])->save();

        $response = $this->actingAsUser($this->agent, $this->organization)
            ->getJson('/api/v1/conversations');

        $response->assertOk()->assertJsonCount(2, 'data');

        // The whole opinion of the inbox: whoever has waited longest is the
        // one you should answer.
        $this->assertSame($waiting->getKey(), $response->json('data.0.id'));
        $this->assertTrue($response->json('data.0.is_awaiting_reply'));
        $this->assertGreaterThanOrEqual(4300, $response->json('data.0.minutes_waiting'));
    }

    public function test_the_summary_answers_the_navigation_in_one_call(): void
    {
        $conversations = $this->app->make(ConversationService::class);

        $conversation = $conversations->forReservation($this->book());
        $conversations->recordInbound($conversation, ['body' => 'Hello?']);
        $conversation->forceFill(['last_inbound_at' => now()->subMinutes(90)])->save();

        $response = $this->actingAsUser($this->agent, $this->organization)
            ->getJson('/api/v1/conversations/summary');

        $response->assertOk()
            ->assertJsonPath('data.inbox', 1)
            ->assertJsonPath('data.unread', 1)
            ->assertJsonPath('data.awaiting_reply', 1)
            ->assertJsonPath('data.unassigned', 1);

        $this->assertGreaterThanOrEqual(89, $response->json('data.longest_wait_minutes'));
    }

    public function test_opening_a_thread_clears_its_unread_marker_but_not_the_clock(): void
    {
        $conversations = $this->app->make(ConversationService::class);

        $conversation = $conversations->forReservation($this->book());
        $conversations->recordInbound($conversation, ['body' => 'Can we park?']);

        $response = $this->actingAsUser($this->agent, $this->organization)
            ->getJson("/api/v1/conversations/{$conversation->getKey()}");

        $response->assertOk()
            ->assertJsonPath('data.unread_count', 0)
            // Reading is not replying.
            ->assertJsonPath('data.is_awaiting_reply', true)
            ->assertJsonCount(1, 'data.messages');
    }

    public function test_sending_says_plainly_when_nothing_was_delivered(): void
    {
        $conversation = $this->app->make(ConversationService::class)
            ->forReservation($this->book());

        $response = $this->actingAsUser($this->agent, $this->organization)
            ->postJson("/api/v1/conversations/{$conversation->getKey()}/messages", [
                'body' => 'Your keys are in the lockbox.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.delivery.simulated', true);

        // An operator pressing send deserves to know at once, not to find out
        // when the guest complains.
        $this->assertNotEmpty($response->json('notice'));
    }

    public function test_an_internal_note_is_marked_as_one(): void
    {
        $conversation = $this->app->make(ConversationService::class)
            ->forReservation($this->book());

        $this->actingAsUser($this->agent, $this->organization)
            ->postJson("/api/v1/conversations/{$conversation->getKey()}/notes", [
                'body' => 'Cleaner cannot finish before 13:00.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_internal_note', true)
            ->assertJsonPath('data.direction', 'internal');
    }

    public function test_a_template_can_be_previewed_before_it_is_sent(): void
    {
        $reservation = $this->book();
        $conversation = $this->app->make(ConversationService::class)->forReservation($reservation);

        $template = MessageTemplate::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Welcome',
            'subject' => 'Your stay at {{ property.name }}',
            'body' => 'Hello {{ guest.first_name }}, your reference is {{ reservation.confirmation_code }}.',
        ]);

        $response = $this->actingAsUser($this->agent, $this->organization)
            ->postJson("/api/v1/conversations/{$conversation->getKey()}/preview", [
                'template_id' => $template->getKey(),
            ]);

        $response->assertOk();

        // Rendered by the same code that sends, so what is approved is what
        // the guest receives.
        $this->assertStringContainsString('Marta', $response->json('data.body'));
        $this->assertStringContainsString($reservation->confirmation_code, $response->json('data.body'));
        $this->assertStringContainsString($this->property->name, $response->json('data.subject'));
    }

    public function test_a_template_with_an_unrecognised_placeholder_is_refused(): void
    {
        $this->actingAsUser($this->agent, $this->organization)
            ->postJson('/api/v1/message-templates', [
                'name' => 'Broken welcome',
                'body' => 'Dear {{ guest.forename }}, welcome.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        // A guest reading the raw placeholder is exactly the failure a
        // template editor exists to prevent.
        $this->assertSame(0, MessageTemplate::query()->count());
    }

    public function test_the_placeholder_vocabulary_is_published_for_the_editor(): void
    {
        $response = $this->actingAsUser($this->agent, $this->organization)
            ->getJson('/api/v1/message-templates/vocabulary');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['Guest', 'Reservation', 'Property'], 'modifiers', 'syntax']);

        // Nothing outside this list resolves, so the list is the whole
        // contract rather than a convenience.
        $this->assertArrayHasKey('guest.first_name', $response->json('data.Guest'));
    }

    public function test_a_template_draft_can_be_checked_without_saving_it(): void
    {
        $response = $this->actingAsUser($this->agent, $this->organization)
            ->postJson('/api/v1/message-templates/validate', [
                'body' => 'Hello {{ guest.first_name }} and {{ guest.nickname }}.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.valid', false);

        $this->assertContains('guest.nickname', $response->json('data.body.unknown'));
        $this->assertContains('guest.first_name', $response->json('data.body.used'));
    }

    public function test_archiving_does_not_delete_and_a_reply_reopens(): void
    {
        $conversations = $this->app->make(ConversationService::class);
        $conversation = $conversations->forReservation($this->book());

        $this->actingAsUser($this->agent, $this->organization)
            ->postJson("/api/v1/conversations/{$conversation->getKey()}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $conversations->recordInbound($conversation->fresh(), ['body' => 'One more thing']);

        $this->actingAsUser($this->agent, $this->organization)
            ->getJson("/api/v1/conversations/{$conversation->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    public function test_reading_the_inbox_does_not_grant_sending(): void
    {
        $conversation = $this->app->make(ConversationService::class)
            ->forReservation($this->book());

        // An analyst reading response times must not be able to send in the
        // company's name.
        $analyst = $this->createUser($this->organization, [RoleRegistry::ACCOUNTANT]);

        $this->actingAsUser($analyst, $this->organization)
            ->postJson("/api/v1/conversations/{$conversation->getKey()}/messages", ['body' => 'Hello'])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------

    private function book(int $startOffset = 20): Reservation
    {
        return $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $this->listing,
            checkIn: $this->date($startOffset),
            checkOut: $this->date($startOffset + 3),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Marta',
                'last_name' => 'Silva',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
            bookedAt: $this->date(0),
        ));
    }

    private function date(int $offsetDays): CarbonImmutable
    {
        return CarbonImmutable::now($this->property->timezone)->startOfDay()->addDays($offsetDays);
    }
}
