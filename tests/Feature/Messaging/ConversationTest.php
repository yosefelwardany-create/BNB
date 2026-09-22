<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Listings\Models\Listing;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Services\ConversationService;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The unified inbox.
 *
 * The claims worth protecting here are about honesty and about the response
 * clock. A message that was recorded locally must never look like one a guest
 * received, and "which guests are waiting" must be answerable from the thread
 * row rather than by aggregating every message in the organization.
 */
class ConversationTest extends TestCase
{
    use RefreshDatabase;

    private ConversationService $conversations;

    private ReservationService $reservations;

    private Property $property;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conversations = $this->app->make(ConversationService::class);
        $this->reservations = $this->app->make(ReservationService::class);

        $organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'max_occupancy' => 4,
            'wifi_network' => 'Casa-Verde',
            'wifi_password' => 'sunshine-42',
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);
    }

    public function test_a_reservation_gets_one_thread_however_often_it_is_asked_for(): void
    {
        $reservation = $this->book();

        $first = $this->conversations->forReservation($reservation);
        $second = $this->conversations->forReservation($reservation);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Conversation::query()->count());
        $this->assertSame($reservation->guest_id, $first->guest_id);
        $this->assertStringContainsString($reservation->confirmation_code, (string) $first->subject);
    }

    public function test_an_inbound_message_starts_the_response_clock(): void
    {
        $conversation = $this->conversations->forReservation($this->book());

        $this->conversations->recordInbound($conversation, [
            'body' => 'Can we check in early?',
        ]);

        $conversation->refresh();

        $this->assertSame(1, (int) $conversation->messages_count);
        $this->assertSame(1, (int) $conversation->unread_count);
        $this->assertNotNull($conversation->last_inbound_at);
        $this->assertTrue($conversation->isAwaitingReply());
        $this->assertNull($conversation->first_response_at);
    }

    public function test_replying_stops_the_clock_once_and_does_not_flatter_the_number(): void
    {
        $conversation = $this->conversations->forReservation($this->book());

        $this->conversations->recordInbound($conversation, ['body' => 'Hello?']);

        // The guest waited half an hour.
        $conversation->forceFill(['last_inbound_at' => now()->subMinutes(30)])->save();

        $this->conversations->send($conversation->fresh(), ['body' => 'Yes, of course.']);

        $conversation->refresh();

        $this->assertNotNull($conversation->first_response_at);
        $this->assertGreaterThanOrEqual(29, (int) $conversation->first_response_minutes);
        $this->assertFalse($conversation->isAwaitingReply());

        $recorded = $conversation->first_response_minutes;

        // A second reply must not overwrite the first response time — that
        // would let a long-ignored thread report as promptly answered.
        $this->conversations->send($conversation->fresh(), ['body' => 'Anything else?']);

        $this->assertSame($recorded, $conversation->fresh()->first_response_minutes);
    }

    public function test_reading_a_thread_is_not_replying_to_it(): void
    {
        $conversation = $this->conversations->forReservation($this->book());

        $this->conversations->recordInbound($conversation, ['body' => 'Hello?']);
        $this->conversations->markRead($conversation->fresh());

        $conversation->refresh();

        $this->assertSame(0, (int) $conversation->unread_count);

        // Still waiting on us. Clearing a badge is not an answer.
        $this->assertTrue($conversation->isAwaitingReply());
        $this->assertNull($conversation->first_response_at);
    }

    public function test_an_internal_note_never_leaves_the_building(): void
    {
        $conversation = $this->conversations->forReservation($this->book());

        $this->conversations->recordInbound($conversation, ['body' => 'Late check-in possible?']);
        $conversation->forceFill(['last_inbound_at' => now()->subHour()])->save();

        $note = $this->conversations->addNote($conversation->fresh(), 'Cleaner cannot finish before 13:00.');

        $conversation->refresh();

        $this->assertTrue((bool) $note->is_internal_note);
        $this->assertSame(Message::INTERNAL, $note->direction);

        // A note is not a reply: the guest is still waiting.
        $this->assertNull($conversation->first_response_at);
        $this->assertTrue($conversation->isAwaitingReply());
        $this->assertNull($conversation->last_outbound_at);
    }

    public function test_a_guest_writing_again_reopens_an_archived_thread(): void
    {
        $conversation = $this->conversations->forReservation($this->book());

        $this->conversations->archive($conversation);
        $this->assertSame(Conversation::STATUS_ARCHIVED, $conversation->fresh()->status);

        $this->conversations->recordInbound($conversation->fresh(), ['body' => 'One more thing...']);

        // Filed away is not finished.
        $this->assertSame(Conversation::STATUS_OPEN, $conversation->fresh()->status);
    }

    public function test_a_guest_writing_overtakes_a_snooze(): void
    {
        $conversation = $this->conversations->forReservation($this->book());

        $this->conversations->snooze($conversation, CarbonImmutable::now()->addDays(3));
        $this->assertSame(Conversation::STATUS_SNOOZED, $conversation->fresh()->status);

        $this->conversations->recordInbound($conversation->fresh(), ['body' => 'Actually, urgent.']);

        $conversation->refresh();

        $this->assertSame(Conversation::STATUS_OPEN, $conversation->status);
        $this->assertNull($conversation->snoozed_until);
    }

    public function test_a_template_is_rendered_against_the_booking(): void
    {
        $reservation = $this->book();
        $conversation = $this->conversations->forReservation($reservation);

        $template = MessageTemplate::query()->create([
            'organization_id' => $this->property->organization_id,
            'name' => 'Arrival instructions',
            'subject' => 'Your stay at {{ property.name }}',
            'body' => "Hello {{ guest.first_name }},\n\nThe Wi-Fi is {{ property.wifi_network }}, "
                ."password {{ property.wifi_password }}.\nYou arrive on {{ check_in_date|long }} "
                .'for {{ nights }} nights. Your reference is {{ reservation.confirmation_code }}.',
        ]);

        $rendered = $this->conversations->renderForConversation($conversation, $template);

        $this->assertStringContainsString($this->property->name, $rendered['subject']);
        $this->assertStringContainsString('Casa-Verde', $rendered['body']);
        $this->assertStringContainsString('sunshine-42', $rendered['body']);
        $this->assertStringContainsString($reservation->confirmation_code, $rendered['body']);

        // The modifier ran: a long date, not an ISO string.
        $this->assertStringNotContainsString($reservation->check_in_date->toDateString(), $rendered['body']);

        // And nothing is left unresolved for the guest to read.
        $this->assertStringNotContainsString('{{', $rendered['body']);
    }

    public function test_an_unknown_placeholder_is_left_visible_rather_than_guessed_at(): void
    {
        $conversation = $this->conversations->forReservation($this->book());

        $template = MessageTemplate::query()->create([
            'organization_id' => $this->property->organization_id,
            'name' => 'Broken',
            'body' => 'Hello {{ guest.forename }}, welcome.',
        ]);

        $rendered = $this->conversations->renderForConversation($conversation, $template);

        // Visible so the author sees what went wrong, rather than silently
        // vanishing from a guest's email or resolving to something wrong.
        $this->assertStringContainsString('{{ guest.forename }}', $rendered['body']);
    }

    public function test_a_message_that_was_not_delivered_does_not_claim_to_be(): void
    {
        $conversation = $this->conversations->forReservation($this->book());

        // The test environment's mailer records rather than sends, which is
        // precisely the situation this must be honest about.
        $message = $this->conversations->send($conversation, ['body' => 'Welcome!']);

        $delivery = $message->fresh()->metadata['delivery'] ?? [];

        $this->assertTrue($delivery['simulated'] ?? false);
        $this->assertNotEmpty($delivery['reason'] ?? null);

        // Sent, never "delivered": nothing confirmed receipt.
        $this->assertSame('sent', $message->fresh()->status);
        $this->assertNull($message->fresh()->delivered_at);
    }

    public function test_a_message_with_nowhere_to_go_is_kept_and_marked_undelivered(): void
    {
        $reservation = $this->book(['email' => null, 'phone' => null, 'first_name' => 'Anon']);

        $conversation = $this->conversations->forReservation($reservation);

        $message = $this->conversations->send($conversation, ['body' => 'Are you there?']);

        $delivery = $message->fresh()->metadata['delivery'] ?? [];

        // Recorded rather than dropped, and the reason it could not be sent is
        // on the record rather than in a log nobody reads.
        $this->assertSame('local', $delivery['transport'] ?? null);
        $this->assertTrue($delivery['simulated'] ?? false);
        $this->assertNotEmpty($delivery['fallback_from'] ?? null);
    }

    public function test_the_snooze_sweeper_wakes_expired_threads_only(): void
    {
        $due = $this->conversations->forReservation($this->book());
        $notDue = $this->conversations->forReservation($this->book(null, 60));

        $this->conversations->snooze($due, CarbonImmutable::now()->addHour());
        $due->forceFill(['snoozed_until' => now()->subMinute()])->save();

        $this->conversations->snooze($notDue, CarbonImmutable::now()->addDays(2));

        $woken = $this->conversations->wakeSnoozed();

        $this->assertSame(1, $woken);
        $this->assertSame(Conversation::STATUS_OPEN, $due->fresh()->status);
        $this->assertSame(Conversation::STATUS_SNOOZED, $notDue->fresh()->status);
    }

    // ------------------------------------------------------------------

    private function book(?array $guest = null, int $startOffset = 20): Reservation
    {
        return $this->reservations->create(new ReservationRequest(
            listing: $this->listing,
            checkIn: $this->date($startOffset),
            checkOut: $this->date($startOffset + 3),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: $guest ?? [
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
