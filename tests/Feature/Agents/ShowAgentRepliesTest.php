<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Domain\Agents\DataObjects\AgentAnswer;
use App\Domain\Agents\Jobs\ReplyToGuestAutomatically;
use App\Domain\Agents\Models\AgentActivity;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Services\GuestAgent;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Guests\Models\Guest;
use App\Domain\Listings\Models\Listing;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConversationService;
use App\Domain\Properties\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * The listing is only worth having if "sent" means a guest read it.
 *
 * Every test here is the same question asked several ways: can somebody tell,
 * from this output alone, whether a guest received anything. A simulated send
 * counted as sent, or a held draft printed without its reason, would make the
 * command worse than no command — it would answer "yes, it's working" when the
 * honest answer is "nothing left the building".
 *
 * Assertions read the whole captured output rather than using
 * `expectsOutputToContain`, which registers one Mockery expectation per
 * substring and so can only match one substring per written line. Several of
 * these facts belong on one line on purpose — the property, the guest and the
 * channel are read together — and an assertion helper must not dictate the
 * layout.
 */
class ShowAgentRepliesTest extends TestCase
{
    use RefreshDatabase;

    private function thread(): Conversation
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['https://api.hostex.io/*' => Http::response(['error_code' => 200, 'data' => []], 200)]);

        $org = $this->createOrganization();
        $this->actingAsUser($this->createUser($org), $org);

        $property = Property::factory()->active()->create(['organization_id' => $org->id, 'name' => 'Den Room']);
        app(AgentBriefStore::class)->save($property, [
            'enabled' => true, 'automatic_guest_replies' => true,
            'auto_send' => ['amenity'], 'may_do' => ['send_message'],
        ]);

        $account = ChannelAccount::create(['organization_id' => $org->id, 'channel' => 'hostex', 'name' => 'Test',
            'status' => 'connected', 'credentials' => ['access_token' => 'test-token']]);
        $listing = Listing::factory()->create(['organization_id' => $org->id, 'property_id' => $property->id]);
        ChannelListing::create(['organization_id' => $org->id, 'channel_account_id' => $account->id,
            'property_id' => $property->id, 'listing_id' => $listing->id,
            'external_listing_id' => 'listing-1', 'status' => 'listed']);

        $guest = Guest::create(['organization_id' => $org->id, 'first_name' => 'Haaris', 'last_name' => 'Khan']);

        $this->travel(1)->seconds();

        return Conversation::create(['organization_id' => $org->id, 'property_id' => $property->id,
            'channel_account_id' => $account->id, 'channel' => 'hostex', 'external_thread_id' => 'thread-1',
            'guest_id' => $guest->id, 'participant_type' => 'guest', 'status' => 'open']);
    }

    private function askAndRun(Conversation $thread, AgentAnswer $answer, string $question = 'What time does the pool open?'): Message
    {
        $message = app(ConversationService::class)->recordInbound($thread, ['body' => $question, 'sent_at' => now()], dispatchEvent: false);

        $this->instance(GuestAgent::class, Mockery::mock(GuestAgent::class)
            ->shouldReceive('answer')->once()->andReturn($answer)->getMock());

        $since = $thread->property->refresh()->settings['agent']['automatic_replies_since'];
        app()->call([new ReplyToGuestAutomatically($message->id, $message->organization_id, $since), 'handle']);

        return $message->refresh();
    }

    /** @param  array<string, mixed>  $options */
    private function listing(array $options = []): string
    {
        Artisan::call('agents:replies', $options);

        return Artisan::output();
    }

    private function sendable(string $reply = 'The pool is open from 8am.'): AgentAnswer
    {
        return new AgentAnswer($reply, 'amenity', 0.96, true, null, provider: 'claude', isSimulated: false);
    }

    public function test_a_reply_that_reached_a_guest_is_listed_with_the_question_and_the_answer(): void
    {
        $this->askAndRun($this->thread(), $this->sendable());

        $output = $this->listing();

        $this->assertStringContainsString('Reached a guest', $output);
        $this->assertStringContainsString('reached a guest', $output);
        $this->assertStringContainsString('What time does the pool open?', $output);
        $this->assertStringContainsString('The pool is open from 8am.', $output);
        $this->assertStringContainsString('Den Room', $output);
        $this->assertStringContainsString('Haaris Khan', $output);
        $this->assertStringContainsString('on its own', $output);
        $this->assertStringContainsString('via claude', $output);
    }

    public function test_a_held_draft_is_listed_with_its_reason_and_the_words_it_wanted_to_use(): void
    {
        $this->askAndRun(
            $this->thread(),
            new AgentAnswer('I think you can cancel free of charge.', 'booking_change', 0.42, false,
                'Below the confidence floor.', withheld: ['cancellation_policy'], provider: 'claude', isSimulated: false),
            'Can I cancel and get a refund?',
        );

        $output = $this->listing();

        $this->assertStringContainsString('Held for a person', $output);
        $this->assertStringContainsString('Can I cancel and get a refund?', $output);
        $this->assertStringContainsString('Below the confidence floor.', $output);

        /*
         * The draft, the agent's reading of the question, how sure it was and
         * what it refused to say. All four existed only inside the prose of an
         * internal note before, where no query could reach them.
         */
        $this->assertStringContainsString('I think you can cancel free of charge.', $output);
        $this->assertStringContainsString('read as booking_change', $output);
        $this->assertStringContainsString('42% sure', $output);
        $this->assertStringContainsString('would not say: cancellation_policy', $output);
    }

    public function test_a_held_reply_is_never_counted_as_having_reached_a_guest(): void
    {
        $this->askAndRun($this->thread(), new AgentAnswer('Draft only.', 'amenity', 0.4, false, 'Not sure enough.', provider: 'claude', isSimulated: false));

        $output = $this->listing();

        $this->assertStringNotContainsString('reached a guest', $output);
        $this->assertStringContainsString('held for a person', $output);
    }

    public function test_a_simulated_send_is_reported_apart_from_one_that_really_went_out(): void
    {
        $thread = $this->thread();

        /*
         * The shape a simulated delivery really leaves behind: status 'sent',
         * which is what makes it dangerous, and `simulated => true`, the only
         * thing distinguishing it from a message a guest read.
         */
        Message::create([
            'organization_id' => $thread->organization_id, 'conversation_id' => $thread->id,
            'direction' => Message::OUTBOUND, 'transport' => 'channel', 'channel' => 'hostex',
            'body' => 'Check-in is at three.', 'author_type' => 'ai', 'is_ai_generated' => true,
            'ai_provider' => 'claude', 'status' => 'sent', 'sent_at' => now(),
            'metadata' => ['automatic_reply_to' => 'nothing', 'delivery' => ['simulated' => true, 'reason' => 'No Hostex token is configured.']],
        ]);

        $output = $this->listing();

        $this->assertStringContainsString('written but never sent', $output);
        $this->assertStringContainsString('No Hostex token is configured.', $output);
        $this->assertStringContainsString('Check-in is at three.', $output);
        $this->assertStringNotContainsString('reached a guest', $output);
    }

    public function test_a_draft_a_person_sent_is_told_apart_from_one_the_agent_sent_itself(): void
    {
        $thread = $this->thread();
        $approver = $this->createUser($thread->organization);

        app(ConversationService::class)->recordInbound($thread, ['body' => 'Is there parking?', 'sent_at' => now()], dispatchEvent: false);
        $this->travel(1)->seconds();

        Message::create([
            'organization_id' => $thread->organization_id, 'conversation_id' => $thread->id,
            'direction' => Message::OUTBOUND, 'transport' => 'channel', 'channel' => 'hostex',
            'body' => 'There is a space behind the building.', 'author_type' => 'ai', 'is_ai_generated' => true,
            'ai_provider' => 'claude', 'status' => 'delivered', 'sent_at' => now(),
            'approved_by_id' => $approver->id,
            'metadata' => ['delivery' => ['simulated' => false]],
        ]);

        $output = $this->listing();

        $this->assertStringContainsString('reached a guest', $output);
        $this->assertStringContainsString('1 a person sent', $output);
        // The question carries no pointer back, so it is found by position.
        $this->assertStringContainsString('Is there parking?', $output);
        $this->assertStringContainsString('sent by '.$approver->name, $output);
    }

    public function test_a_draft_a_person_carried_out_by_hand_counts_as_having_reached_the_guest(): void
    {
        $thread = $this->thread();
        $approver = $this->createUser($thread->organization);

        /*
         * No delivery metadata, because no transport of ours ran. Treating that
         * as a simulation would file the one case where a person definitely did
         * the work under "nothing left the building".
         */
        Message::create([
            'organization_id' => $thread->organization_id, 'conversation_id' => $thread->id,
            'direction' => Message::OUTBOUND, 'transport' => 'manual',
            'body' => 'I pasted this into Airbnb myself.', 'author_type' => 'ai', 'is_ai_generated' => true,
            'status' => 'delivered', 'sent_at' => now(), 'delivered_at' => now(),
            'approved_by_id' => $approver->id,
            'metadata' => ['delivered_by_hand' => true],
        ]);

        $output = $this->listing();

        $this->assertStringContainsString('reached a guest', $output);
        $this->assertStringContainsString('I pasted this into Airbnb myself.', $output);
        $this->assertStringContainsString('carried out by hand by '.$approver->name, $output);
        $this->assertStringNotContainsString('written but never sent', $output);
    }

    public function test_an_owner_thread_is_not_listed_under_what_reached_a_guest(): void
    {
        $thread = $this->thread();

        $owners = Conversation::create(['organization_id' => $thread->organization_id,
            'property_id' => $thread->property_id, 'participant_type' => 'owner', 'status' => 'open']);

        Message::create([
            'organization_id' => $thread->organization_id, 'conversation_id' => $owners->id,
            'direction' => Message::OUTBOUND, 'transport' => 'email',
            'body' => 'Your October payout is on its way.', 'author_type' => 'ai', 'is_ai_generated' => true,
            'status' => 'delivered', 'sent_at' => now(),
            'metadata' => ['delivery' => ['simulated' => false]],
        ]);

        $output = $this->listing();

        $this->assertStringNotContainsString('Your October payout', $output);
        $this->assertStringContainsString('the agents have said nothing in this window', $output);
        // And the heading is explicit about the scope rather than implying all of it.
        $this->assertStringContainsString('What the agents said to guests', $output);
    }

    public function test_one_failed_attempt_is_listed_once_and_not_twice(): void
    {
        $thread = $this->thread();

        $inbound = app(ConversationService::class)->recordInbound($thread, ['body' => 'Hello?', 'sent_at' => now()], dispatchEvent: false);

        $reply = Message::create([
            'organization_id' => $thread->organization_id, 'conversation_id' => $thread->id,
            'direction' => Message::OUTBOUND, 'transport' => 'channel', 'channel' => 'hostex',
            'body' => 'Hello back.', 'author_type' => 'ai', 'is_ai_generated' => true,
            'status' => 'failed', 'failure_reason' => 'Hostex refused the thread.',
            'metadata' => ['automatic_reply_to' => $inbound->id, 'delivery' => ['simulated' => false]],
        ]);

        AgentActivity::record(['organization_id' => $thread->organization_id, 'property_id' => $thread->property_id,
            'conversation_id' => $thread->id, 'kind' => AgentActivity::KIND_FAILED, 'summary' => 'Automatic delivery failed.',
            'detail' => ['inbound_message_id' => $inbound->id, 'reply_id' => $reply->id], 'is_autonomous' => true]);

        $output = $this->listing();

        $this->assertStringContainsString('Hostex refused the thread.', $output);
        // One attempt, one line — not one from `messages` and another from the log.
        $this->assertSame(1, substr_count($output, 'Hello back.'));
        $this->assertStringContainsString('  1  failed', $output);
    }

    public function test_a_property_name_that_matches_nothing_is_an_error_not_a_full_listing(): void
    {
        $this->askAndRun($this->thread(), $this->sendable());

        $code = Artisan::call('agents:replies', ['--property' => 'Nowhere House']);
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('No property matches "Nowhere House"', $output);
        $this->assertStringNotContainsString('The pool is open from 8am.', $output);
        // And it names what does exist, so the next attempt can succeed.
        $this->assertStringContainsString('Den Room', $output);
    }

    public function test_a_property_that_does_match_narrows_the_listing_to_it(): void
    {
        $this->askAndRun($this->thread(), $this->sendable());

        $output = $this->listing(['--property' => 'Den']);

        $this->assertStringContainsString('Den Room', $output);
        $this->assertStringContainsString('The pool is open from 8am.', $output);
    }

    public function test_the_sent_filter_leaves_out_what_was_only_held(): void
    {
        $thread = $this->thread();
        $this->askAndRun($thread, $this->sendable('A sent one.'));
        $this->travel(1)->seconds();
        $this->askAndRun($thread, new AgentAnswer('A held one.', 'amenity', 0.3, false, 'Not sure enough.', provider: 'claude', isSimulated: false), 'And the sauna?');

        $output = $this->listing(['--sent' => true]);

        $this->assertStringContainsString('A sent one.', $output);
        $this->assertStringNotContainsString('A held one.', $output);
    }

    public function test_a_window_that_predates_the_reply_leaves_it_out(): void
    {
        $this->askAndRun($this->thread(), $this->sendable());
        $this->travel(10)->days();

        $output = $this->listing(['--days' => 2]);

        $this->assertStringNotContainsString('The pool is open from 8am.', $output);
        $this->assertStringContainsString('the agents have said nothing in this window', $output);
    }

    public function test_an_empty_window_says_so_and_points_at_the_other_command(): void
    {
        $this->thread();

        $output = $this->listing();

        $this->assertStringContainsString('the agents have said nothing in this window', $output);
        $this->assertStringContainsString('agents:why-no-reply', $output);
    }

    public function test_a_long_reply_is_shortened_unless_the_whole_thing_is_asked_for(): void
    {
        $long = 'The pool opens at eight. '.str_repeat('There are towels in the cupboard by the door. ', 20).'Enjoy your swim.';
        $this->askAndRun($this->thread(), $this->sendable($long));

        $this->assertStringNotContainsString('Enjoy your swim.', $this->listing());
        $this->assertStringContainsString('Enjoy your swim.', $this->listing(['--full' => true]));
    }
}
