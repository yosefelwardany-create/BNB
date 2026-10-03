<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Domain\Agents\Enums\AgentCapability;
use App\Domain\Agents\Exceptions\AgentNotConfiguredException;
use App\Domain\Agents\Models\AgentAction;
use App\Domain\Agents\Models\AgentActivity;
use App\Domain\Agents\Services\AgentActions;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Services\OperatorActions;
use App\Domain\Availability\Models\CalendarBlock;
use App\Domain\Availability\Models\CalendarDay;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Guests\Models\Guest;
use App\Domain\Listings\Models\Listing;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The agent doing things, rather than only saying them.
 *
 * Everything here turns on one distinction: an agent asked to do something
 * produces a *proposal*, and a proposal is not an action. The waiting is the
 * structure — if approving were a flag checked after the fact, every action
 * would be autonomous with a setting that read otherwise.
 *
 * So the tests that matter are the ones about what cannot happen:
 *
 *  - a capability nobody granted is refused, however it was asked for;
 *  - cancelling a booking is never unattended, and no setting changes that;
 *  - approving needs the permission the thing itself needs, or the agent becomes
 *    a way around the permission system;
 *  - a proposal nobody decided on stops being approvable rather than aging into
 *    an approval of a situation that has moved on;
 *  - blocking nights runs the same conflict check the calendar screen runs,
 *    because an agent told to "close next weekend" is exactly the caller most
 *    likely to be told it by somebody who forgot about the booking.
 */
class AgentActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    public function test_operator_chat_executes_local_action_and_refuses_external_or_simulated_actions(): void
    {
        Http::preventStrayRequests();
        $property = $this->property(['may_do' => ['block_dates', 'send_message'], 'may_do_alone' => ['block_dates', 'send_message']]);
        $runner = app(OperatorActions::class);
        $payload = json_encode(['action' => ['capability' => 'block_dates', 'arguments' => ['from' => '2027-03-06', 'to' => '2027-03-06']]]);
        $this->assertStringContainsString('simulated', $runner->respond($property, $this->user, $payload, false));
        $this->assertDatabaseCount('calendar_blocks', 0);
        $this->assertStringContainsString('Completed:', $runner->respond($property, $this->user, $payload));
        $this->assertStringContainsString('Completed:', $runner->respond($property, $this->user, $payload));
        $this->assertDatabaseCount('calendar_blocks', 1);
        $this->assertDatabaseHas('calendar_blocks', ['property_id' => $property->id, 'start_date' => '2027-03-06', 'end_date' => '2027-03-07']);
        $this->assertStringContainsString('not enabled', $runner->respond($property, $this->user, json_encode(['action' => ['capability' => 'send_message', 'arguments' => ['body' => 'Hi']]])));
        $this->assertStringContainsString('not enabled', $runner->respond($property, null, $payload));
        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_partial_unblock_preserves_other_nights_and_imported_blocks(): void
    {
        $property = $this->property(['may_do' => ['unblock_dates'], 'may_do_alone' => ['unblock_dates']]);
        $fields = ['organization_id' => $this->organization->id, 'property_id' => $property->id, 'start_date' => '2027-03-01', 'end_date' => '2027-03-10', 'kind' => 'manual'];
        CalendarBlock::query()->create($fields);
        $external = CalendarBlock::query()->create(array_merge($fields, ['kind' => 'external', 'source' => 'hostex', 'external_id' => 'external-block']));
        $action = app(AgentActions::class)->propose($property, AgentCapability::UnblockDates, ['from' => '2027-03-04', 'to' => '2027-03-05'], 'Reopen two nights', $this->user);
        $this->assertSame('executed', $action->status);
        $this->assertSame('2027-03-10', $external->fresh()->end_date->toDateString());
        $this->assertDatabaseHas('calendar_blocks', ['source' => 'manual', 'start_date' => '2027-03-01', 'end_date' => '2027-03-04']);
        $this->assertDatabaseHas('calendar_blocks', ['source' => 'manual', 'start_date' => '2027-03-06', 'end_date' => '2027-03-10']);
    }

    public function test_blocking_one_booked_night_fails_and_other_property_notes_are_refused(): void
    {
        $property = $this->property(['may_do' => ['block_dates', 'add_note'], 'may_do_alone' => ['block_dates', 'add_note']]);
        $reservation = $this->reservation($property);
        $date = $reservation->check_in_date->toDateString();
        $action = app(AgentActions::class)->propose($property, AgentCapability::BlockDates, ['from' => $date, 'to' => $date], 'Block booked night', $this->user);
        $this->assertSame('failed', $action->status);
        $this->assertDatabaseCount('calendar_blocks', 0);
        $thread = $this->conversationOn($this->property());
        $this->expectException(AgentNotConfiguredException::class);
        app(AgentActions::class)->propose($property, AgentCapability::AddNote, ['conversation_id' => $thread->id, 'body' => 'Wrong property'], 'Note', $this->user);
    }

    public function test_a_capability_nobody_granted_is_refused(): void
    {
        // The brief grants nothing, which is the default: an agent that answers
        // and changes nothing.
        $property = $this->property();

        $this->postJson("/api/v1/properties/{$property->getKey()}/agent/actions", [
            'capability' => AgentCapability::BlockDates->value,
            'summary' => 'Close the first weekend of March for painting.',
            'arguments' => ['from' => '2026-03-06', 'to' => '2026-03-08'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'not allowed to block nights'));

        $this->assertSame(0, AgentAction::query()->count());
    }

    public function test_a_granted_capability_waits_for_a_person_by_default(): void
    {
        $property = $this->property(['may_do' => [AgentCapability::BlockDates->value]]);

        $response = $this->postJson("/api/v1/properties/{$property->getKey()}/agent/actions", [
            'capability' => AgentCapability::BlockDates->value,
            'summary' => 'Close the first weekend of March for painting.',
            'arguments' => ['from' => '2026-03-06', 'to' => '2026-03-08'],
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', AgentAction::STATUS_PROPOSED)
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.was_autonomous', false)
            // The sentence somebody reads before pressing Approve travels with
            // the row rather than living in the frontend.
            ->assertJsonPath('data.consequence', AgentCapability::BlockDates->consequence());

        // Granted is not the same as done. Nothing has reached the calendar.
        $this->assertSame(0, CalendarBlock::query()->count());

        $id = $response->json('data.id');

        $this->postJson("/api/v1/properties/{$property->getKey()}/agent/actions/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', AgentAction::STATUS_EXECUTED);

        $block = CalendarBlock::query()->sole();
        $this->assertSame('2026-03-06', $block->start_date->toDateString());
        // Stored exclusive, as a booking is: a block "to the 8th" that closed
        // the 9th would quietly cost a night.
        $this->assertSame('2026-03-09', $block->end_date->toDateString());
    }

    public function test_a_capability_the_brief_allows_alone_runs_without_waiting(): void
    {
        $property = $this->property([
            'may_do' => [AgentCapability::BlockDates->value],
            'may_do_alone' => [AgentCapability::BlockDates->value],
        ]);

        $this->postJson("/api/v1/properties/{$property->getKey()}/agent/actions", [
            'capability' => AgentCapability::BlockDates->value,
            'summary' => 'Close the first weekend of March for painting.',
            'arguments' => ['from' => '2026-03-06', 'to' => '2026-03-08'],
        ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', AgentAction::STATUS_EXECUTED)
            ->assertJsonPath('data.was_autonomous', true);

        $this->assertSame(1, CalendarBlock::query()->count());

        // Recorded as something nobody read first, which is the question the
        // activity log exists to answer.
        $activity = AgentActivity::query()->where('kind', AgentActivity::KIND_ACTED)->sole();
        $this->assertTrue($activity->is_autonomous);
        $this->assertStringContainsString('Block nights', (string) $activity->summary);
    }

    public function test_cancelling_a_booking_can_never_be_made_autonomous(): void
    {
        $property = $this->property([
            'may_do' => [AgentCapability::CancelReservation->value],
            // Asked for, and refused — not by a preference but by
            // AgentCapability::mayEverBeAutonomous(), which is a constant.
            'may_do_alone' => [AgentCapability::CancelReservation->value],
        ]);

        $brief = $this->app->make(AgentBriefStore::class)->for($property->fresh());

        $this->assertSame([AgentCapability::CancelReservation->value], $brief->mayDo);
        $this->assertSame([], $brief->mayDoAlone);
    }

    public function test_the_api_refuses_to_store_cancelling_as_unattended(): void
    {
        $property = $this->property();

        $this->patchJson("/api/v1/properties/{$property->getKey()}/agent", [
            'may_do' => [AgentCapability::CancelReservation->value],
            'may_do_alone' => [AgentCapability::CancelReservation->value],
        ])
            ->assertStatus(422)
            // Named, so the person reading it learns the rule rather than
            // thinking the form is broken.
            ->assertJsonValidationErrors(['may_do_alone.0']);
    }

    public function test_approving_needs_the_permission_the_thing_itself_needs(): void
    {
        $property = $this->property(['may_do' => [AgentCapability::CancelReservation->value]]);

        $reservation = $this->reservation($property);

        $action = AgentAction::query()->create([
            'property_id' => $property->getKey(),
            'reservation_id' => $reservation->getKey(),
            'capability' => AgentCapability::CancelReservation,
            'arguments' => ['reason' => 'The guest asked.'],
            'summary' => 'Cancel the booking, the guest asked.',
            'expires_at' => CarbonImmutable::now()->addHours(48),
        ]);

        /*
         * A cleaner can see properties and cannot cancel bookings. Without the
         * capability check they could approve the agent's proposal and cancel one
         * anyway — the agent as a way around the permission system.
         */
        $cleaner = $this->createUser($this->organization, [RoleRegistry::CLEANER]);
        $this->actingAsUser($cleaner, $this->organization);

        $this->postJson("/api/v1/properties/{$property->getKey()}/agent/actions/{$action->getKey()}/approve")
            ->assertStatus(403);

        $this->assertSame(
            AgentAction::STATUS_PROPOSED,
            $action->fresh()->status,
            'A refused approval must leave the proposal exactly as it was.',
        );
    }

    public function test_a_proposal_nobody_decided_on_stops_being_approvable(): void
    {
        $property = $this->property(['may_do' => [AgentCapability::BlockDates->value]]);

        $action = AgentAction::factory()->lapsed()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
            'capability' => AgentCapability::BlockDates,
            'arguments' => ['from' => '2026-03-06', 'to' => '2026-03-08'],
        ]);

        // Still `proposed` in the column — nothing has swept it yet — and
        // already closed to approval, which is the distinction `isOpen` exists
        // to make.
        $this->assertSame(AgentAction::STATUS_PROPOSED, $action->status);
        $this->assertFalse($action->isOpen());

        $this->postJson("/api/v1/properties/{$property->getKey()}/agent/actions/{$action->getKey()}/approve")
            ->assertStatus(422);

        $this->assertSame(0, CalendarBlock::query()->count());

        // And the queue stops counting it, so the badge tells the truth.
        $this->getJson("/api/v1/properties/{$property->getKey()}/agent/actions?open=1")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.waiting', 0);

        $this->artisan('agents:expire-actions')->assertSuccessful();

        $this->assertSame(AgentAction::STATUS_EXPIRED, $action->fresh()->status);
        // Written down rather than silently flipped: "the agent proposed it and
        // nobody looked" is worth being able to find.
        $this->assertSame(
            1,
            AgentActivity::query()->where('kind', AgentActivity::KIND_EXPIRED)->count(),
        );
    }

    public function test_blocking_nights_refuses_to_hide_a_real_booking(): void
    {
        $property = $this->property([
            'may_do' => [AgentCapability::BlockDates->value],
            'may_do_alone' => [AgentCapability::BlockDates->value],
        ]);

        $reservation = $this->reservation($property);

        $action = $this->app->make(AgentActions::class)->propose(
            $property->fresh(),
            AgentCapability::BlockDates,
            [
                'from' => $reservation->check_in_date->toDateString(),
                'to' => $reservation->check_out_date->toDateString(),
            ],
            'Close those nights for maintenance.',
        );

        $this->assertSame(AgentAction::STATUS_FAILED, $action->status);
        // The refusal names the booking, because "that failed" sends somebody
        // looking at the agent when the answer is on the calendar.
        $this->assertStringContainsString(
            (string) $reservation->confirmation_code,
            (string) $action->outcome,
        );
        $this->assertSame(0, CalendarBlock::query()->count());
    }

    public function test_a_rate_change_writes_the_same_row_the_calendar_screen_writes(): void
    {
        $property = $this->property([
            'may_do' => [AgentCapability::SetRate->value],
            'may_do_alone' => [AgentCapability::SetRate->value],
        ]);

        // The property's own listing, created with it — not an extra one. A rate
        // set by the agent has to land where the pricing screens read it.
        $listing = $property->listings()->where('is_primary', true)->sole();

        $action = $this->app->make(AgentActions::class)->propose(
            $property->fresh(),
            AgentCapability::SetRate,
            ['from' => '2026-07-01', 'to' => '2026-07-03', 'amount_minor_units' => 14500],
            'Put July up for the festival.',
        );

        $this->assertSame(AgentAction::STATUS_EXECUTED, $action->status, (string) $action->outcome);

        $days = CalendarDay::query()
            ->where('listing_id', $listing->getKey())
            ->orderBy('calendar_date')
            ->get();

        $this->assertCount(3, $days, 'The range is inclusive of both ends.');
        $this->assertSame([14500, 14500, 14500], $days->pluck('rate_override')->all());
    }

    public function test_a_reply_goes_out_through_the_channel_and_is_recorded_as_sent(): void
    {
        Http::fake([
            'https://api.hostex.io/*' => Http::response(['data' => ['id' => 'hx-msg-9']], 200),
        ]);

        $property = $this->property([
            'may_do' => [AgentCapability::SendMessage->value],
            'may_do_alone' => [AgentCapability::SendMessage->value],
        ]);

        $conversation = $this->conversationOn($property);

        $action = $this->app->make(AgentActions::class)->propose(
            $property->fresh(),
            AgentCapability::SendMessage,
            ['conversation_id' => $conversation->getKey(), 'body' => 'The lift is out until March, sorry.'],
            'Answer the guest about the lift.',
        );

        $this->assertSame(AgentAction::STATUS_EXECUTED, $action->status);

        $message = $conversation->messages()->where('is_ai_generated', true)->sole();

        /*
         * Sent through the one path that sends anything.
         *
         * `transport` is `channel`, which means the reply went into the guest's
         * own thread rather than out as an email they would never connect to
         * their booking — and it is the existing transport that put it there, so
         * the agent's reply and a person's reply are the same mechanism.
         */
        $this->assertSame('channel', $message->transport);
        $this->assertContains($message->status, ['sent', 'delivered']);
        $this->assertSame('hx-msg-9', $message->external_message_id);
        $this->assertSame('hx-msg-9', $action->external_reference);
    }

    public function test_a_channel_refusal_is_reported_rather_than_counted_as_done(): void
    {
        // Hostex answers errors with HTTP 200 and a code in the body, so a
        // status-only check would read this as a delivered message.
        Http::fake([
            'https://api.hostex.io/*' => Http::response(
                ['error_code' => 403, 'error_msg' => 'This conversation is closed.'],
                200,
            ),
        ]);

        $property = $this->property([
            'may_do' => [AgentCapability::SendMessage->value],
            'may_do_alone' => [AgentCapability::SendMessage->value],
        ]);

        $conversation = $this->conversationOn($property);

        $action = $this->app->make(AgentActions::class)->propose(
            $property->fresh(),
            AgentCapability::SendMessage,
            ['conversation_id' => $conversation->getKey(), 'body' => 'The lift is out until March, sorry.'],
            'Answer the guest about the lift.',
        );

        $this->assertSame(AgentAction::STATUS_FAILED, $action->status);
        $this->assertStringContainsString('Access denied', (string) $action->outcome);

        /*
         * And the attempt is still on the thread, marked failed.
         *
         * Not deleted: somebody reading the conversation has to be able to see
         * that a reply was tried and did not land. A thread that showed the
         * guest's question and nothing else would send them looking for a bug in
         * the agent when the answer is that the channel refused it.
         */
        $message = $conversation->messages()->where('is_ai_generated', true)->sole();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('Access denied', (string) $message->failure_reason);
    }

    public function test_an_action_at_another_property_is_not_approvable_through_this_one(): void
    {
        $property = $this->property(['may_do' => [AgentCapability::AddNote->value]]);
        $other = $this->app->make(PropertyService::class)->create([
            'name' => 'Blue Room',
            'property_type' => 'apartment',
            'address_line_1' => 'Rua da Prata 4',
            'postal_code' => '1100-052',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'max_occupancy' => 2,
            'base_rate' => 9000,
        ]);

        $action = AgentAction::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $other->getKey(),
        ]);

        $this->postJson("/api/v1/properties/{$property->getKey()}/agent/actions/{$action->getKey()}/approve")
            ->assertStatus(404);
    }

    public function test_the_settings_payload_says_what_each_action_costs(): void
    {
        $property = $this->property();

        $response = $this->getJson("/api/v1/properties/{$property->getKey()}/agent")->assertOk();

        $actions = collect($response->json('data.capabilities.actions'))->keyBy('key');

        $this->assertFalse($actions[AgentCapability::CancelReservation->value]['may_ever_be_autonomous']);
        $this->assertSame('reservations.cancel', $actions[AgentCapability::CancelReservation->value]['permission']);
        // The only one on by default is the one nobody is harmed by.
        $this->assertTrue($actions[AgentCapability::AddNote->value]['defaults_to_autonomous']);
        $this->assertFalse($actions[AgentCapability::SendMessage->value]['defaults_to_autonomous']);
    }

    // ---------------------------------------------------------------- fixtures

    private function property(array $brief = []): Property
    {
        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);
        $this->user = $this->createUser($this->organization);
        $this->actingAsUser($this->user, $this->organization);

        $property = $this->app->make(PropertyService::class)->create([
            'name' => 'Yellow Room',
            'property_type' => 'apartment',
            'address_line_1' => 'Rua dos Remédios 12',
            'postal_code' => '1100-513',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'max_occupancy' => 2,
            'base_rate' => 9000,
        ]);

        $this->app->make(PropertyService::class)->activate($property);

        $this->app->make(AgentBriefStore::class)->save($property, $brief + ['enabled' => true, 'bot_name' => 'Alex']);

        return $property->fresh();
    }

    private function listing(Property $property): Listing
    {
        return Listing::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
            'status' => 'published',
        ]);
    }

    private function reservation(Property $property): Reservation
    {
        $guest = Guest::query()->create([
            'organization_id' => $this->organization->getKey(),
            'first_name' => 'Marta',
            'last_name' => 'Silva',
            'email' => 'marta@example.test',
        ]);

        return $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $this->listing($property),
            checkIn: CarbonImmutable::now()->addDays(20),
            checkOut: CarbonImmutable::now()->addDays(23),
            adults: 2,
            guest: $guest,
        ));
    }

    /**
     * A property connected to a channel, with a guest thread on it.
     */
    private function conversationOn(Property $property)
    {
        $account = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'hostex',
            'name' => 'Hostex',
            'status' => 'connected',
            'credentials' => ['access_token' => 'token-for-tests'],
        ]);

        ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $account->getKey(),
            'property_id' => $property->getKey(),
            'listing_id' => $this->listing($property)->getKey(),
            'external_listing_id' => 'hx-listing-1',
            'status' => 'listed',
        ]);

        return Conversation::query()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
            'channel' => 'hostex',
            'subject' => 'About the lift',
            'external_thread_id' => 'hx-thread-1',
            'status' => 'open',
        ]);
    }
}
