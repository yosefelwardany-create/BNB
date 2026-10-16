<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Domain\Agents\DataObjects\AgentAnswer;
use App\Domain\Agents\Enums\AgentCapability;
use App\Domain\Agents\Jobs\ReplyToGuestAutomatically;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Services\GuestAgent;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelMessageImporter;
use App\Domain\Integrations\DataObjects\ChannelMessagePayload;
use App\Domain\Listings\Models\Listing;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConversationService;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Properties\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class AutomaticGuestRepliesTest extends TestCase
{
    use RefreshDatabase;

    private function thread(bool $enabled = true, bool $failure = false): Conversation
    {
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['https://api.hostex.io/*' => Http::response(['error_code' => $failure ? 500 : 200, 'data' => []], $failure ? 500 : 200)]);
        $org = $this->createOrganization();
        $this->actingAsUser($this->createUser($org), $org);
        $property = Property::factory()->active()->create(['organization_id' => $org->id]);
        app(AgentBriefStore::class)->save($property, ['enabled' => true, 'automatic_guest_replies' => $enabled, 'auto_send' => ['amenity'], 'may_do' => ['send_message']]);
        $account = ChannelAccount::create(['organization_id' => $org->id, 'channel' => 'hostex', 'name' => 'Test', 'status' => 'connected', 'credentials' => ['access_token' => 'test-token']]);
        $listing = Listing::factory()->create(['organization_id' => $org->id, 'property_id' => $property->id]);
        ChannelListing::create(['organization_id' => $org->id, 'channel_account_id' => $account->id, 'property_id' => $property->id, 'listing_id' => $listing->id, 'external_listing_id' => 'listing-1', 'status' => 'listed']);
        $this->travel(1)->seconds();

        return Conversation::create(['organization_id' => $org->id, 'property_id' => $property->id, 'channel_account_id' => $account->id, 'channel' => 'hostex', 'external_thread_id' => 'thread-1', 'participant_type' => 'guest', 'status' => 'open']);
    }

    private function inbound(Conversation $thread, bool $old = false): Message
    {
        return app(ConversationService::class)->recordInbound($thread, ['body' => 'Is there wifi?', 'sent_at' => $old ? now()->subDay() : now()], dispatchEvent: false);
    }

    private function runReply(Message $message): void
    {
        $since = $message->conversation->property->settings['agent']['automatic_replies_since'];
        app()->call([new ReplyToGuestAutomatically($message->id, $message->organization_id, $since), 'handle']);
    }

    private function answer(bool $automatic = true, bool $simulated = false): AgentAnswer
    {
        return new AgentAnswer('Wifi is available.', 'amenity', 0.99, $automatic, $automatic ? null : 'Needs review', isSimulated: $simulated, provider: 'claude');
    }

    public function test_opt_in_sends_once_and_records_authority_without_manual_approval(): void
    {
        $thread = $this->thread();
        $message = $this->inbound($thread);
        Queue::assertPushed(ReplyToGuestAutomatically::class, 1);
        $this->instance(GuestAgent::class, Mockery::mock(GuestAgent::class)->shouldReceive('answer')->once()->andReturn($this->answer())->getMock());
        $this->runReply($message);
        $this->runReply($message);
        Http::assertSentCount(1);
        $this->assertSame('sent', $message->fresh()->metadata['automatic_reply']['status']);
        $outbound = $thread->messages()->where('direction', 'outbound')->sole();
        $this->assertSame($message->id, $outbound->metadata['automatic_reply_to']);
        $this->assertNotEmpty($outbound->metadata['authorized_by']);
        $this->assertNull($outbound->approved_by_id);
    }

    public function test_the_people_who_run_the_place_are_told_what_the_agent_did(): void
    {
        $thread = $this->thread();
        $message = $this->inbound($thread);
        $this->instance(GuestAgent::class, Mockery::mock(GuestAgent::class)->shouldReceive('answer')->once()->andReturn($this->answer())->getMock());
        $this->runReply($message);

        /*
         * The activity log is the record; this is the interruption, and they are
         * not the same thing. Nobody reads a log. Until now an agent could
         * answer a guest and the only trace was a row somebody would have to go
         * looking for, so an inbox that had been handled and one that had been
         * ignored looked identical.
         */
        $sent = Notification::query()->where('type', 'agent.replied')->first();

        $this->assertNotNull($sent, 'Replying on its own should tell somebody.');
        $this->assertSame('low', $sent->priority);
        // The thread, not the screen: a notification you cannot act on is half
        // a notification.
        $this->assertStringContainsString($thread->getKey(), (string) $sent->url);
    }

    public function test_being_unsure_is_reported_with_the_question_that_caused_it(): void
    {
        $thread = $this->thread();
        $message = $this->inbound($thread);
        $this->instance(GuestAgent::class, Mockery::mock(GuestAgent::class)->shouldReceive('answer')->once()->andReturn($this->answer(automatic: false))->getMock());
        $this->runReply($message);

        $held = Notification::query()->where('type', 'agent.held')->first();

        $this->assertNotNull($held, 'Holding a reply is the event most worth being told about.');

        /*
         * The guest's own words, carried on the notification.
         *
         * A held reply is a gap in what the property has written down, and the
         * question is that gap stated by the person who found it. Carrying it
         * here is what lets the repair — a sentence typed into the property's
         * knowledge base — start from the bell instead of from a hunt through
         * the inbox for which message it was.
         */
        $this->assertSame('Is there wifi?', $held->data['question']);
        $this->assertSame('held', $held->data['status']);
        $this->assertSame($thread->property_id, $held->data['property_id']);
        $this->assertNotEmpty($held->data['reason']);
    }

    public function test_a_failure_says_so_in_the_thread_where_somebody_will_see_it(): void
    {
        $thread = $this->thread();
        $message = $this->inbound($thread);

        $this->instance(GuestAgent::class, Mockery::mock(GuestAgent::class)
            ->shouldReceive('answer')->once()
            ->andThrow(new \RuntimeException('No Anthropic API key is configured.'))
            ->getMock());

        $this->runReply($message);

        /*
         * The real one, word for word. Every gate passed, the agent tried to
         * answer, and the worker had no API key — while the connection screen
         * reported the provider as configured, because it was asking the web
         * process, which had one.
         *
         * A held reply already wrote an internal note. A failed one wrote
         * nothing anybody would see, so an agent that could not reach a model
         * at all looked exactly like an agent with nothing to say: an
         * unanswered guest. Finding out took a shell command on a production
         * server.
         */
        $note = $thread->messages()->where('is_internal_note', true)->latest('id')->first();

        $this->assertNotNull($note, 'A failed automatic reply must leave a trace in the thread.');
        $this->assertStringContainsString('could not answer this automatically', $note->body);
        $this->assertStringContainsString('Anthropic API key', $note->body);

        // And the guest is sent nothing at all, which is the one thing worse
        // than silence.
        $this->assertSame(0, $thread->messages()->where('direction', 'outbound')->where('is_internal_note', false)->count());
    }

    public function test_default_off_and_history_never_schedule(): void
    {
        $thread = $this->thread(false);
        $this->inbound($thread);
        app(AgentBriefStore::class)->save($thread->property, ['automatic_guest_replies' => true]);
        $thread->unsetRelation('property');
        $this->inbound($thread, true);
        Queue::assertNotPushed(ReplyToGuestAutomatically::class);
        Http::assertNothingSent();
    }

    public function test_disable_while_drafting_prevents_delivery(): void
    {
        $thread = $this->thread();
        $message = $this->inbound($thread);
        $this->instance(GuestAgent::class, Mockery::mock(GuestAgent::class)->shouldReceive('answer')->once()->andReturnUsing(function () use ($thread) {
            app(AgentBriefStore::class)->save($thread->property, ['automatic_guest_replies' => false]);

            return $this->answer();
        })->getMock());
        $this->runReply($message);
        Http::assertNothingSent();
        $this->assertSame('held', $message->fresh()->metadata['automatic_reply']['status']);
    }

    public function test_answered_thread_is_skipped_without_calling_model(): void
    {
        $thread = $this->thread();
        $message = $this->inbound($thread);
        $this->travel(1)->seconds();
        app(ConversationService::class)->recordDeliveredElsewhere($thread, ['body' => 'Already answered']);
        $this->instance(GuestAgent::class, Mockery::mock(GuestAgent::class)->shouldNotReceive('answer')->getMock());
        $this->runReply($message);
        Http::assertNothingSent();
    }

    public function test_held_or_simulated_reply_stays_an_internal_draft(): void
    {
        $thread = $this->thread();
        $message = $this->inbound($thread);
        $this->instance(GuestAgent::class, Mockery::mock(GuestAgent::class)->shouldReceive('answer')->once()->andReturn($this->answer(false, true))->getMock());
        $this->runReply($message);
        Http::assertNothingSent();
        $this->assertSame('held', $message->fresh()->metadata['automatic_reply']['status']);
        $this->assertSame(1, $thread->messages()->where('is_internal_note', true)->count());
    }

    public function test_lost_send_permission_prevents_model_and_delivery(): void
    {
        $thread = $this->thread();
        $message = $this->inbound($thread);
        app(AgentBriefStore::class)->save($thread->property, ['may_do' => []]);
        $message->unsetRelation('conversation');
        $this->instance(GuestAgent::class, Mockery::mock(GuestAgent::class)->shouldNotReceive('answer')->getMock());
        $this->runReply($message);
        Http::assertNothingSent();
    }

    public function test_channel_failure_is_not_retried_or_routed_elsewhere(): void
    {
        $thread = $this->thread(failure: true);
        $message = $this->inbound($thread);
        $this->instance(GuestAgent::class, Mockery::mock(GuestAgent::class)->shouldReceive('answer')->once()->andReturn($this->answer())->getMock());
        $this->runReply($message);
        $this->runReply($message);
        Http::assertSentCount(1);
        $this->assertSame('failed', $message->fresh()->metadata['automatic_reply']['status']);
        $this->assertSame('channel', $thread->messages()->where('direction', 'outbound')->sole()->transport);
    }

    public function test_import_without_events_schedules_new_messages_but_not_replays_or_missing_dates(): void
    {
        $thread = $this->thread();
        $importer = app(ChannelMessageImporter::class);
        $payload = new ChannelMessagePayload('Wifi?', 'thread-1', 'message-1', sentAt: now()->toDateTimeImmutable());
        $account = ChannelAccount::findOrFail($thread->channel_account_id);
        $importer->record($account, $payload, dispatchEvent: false);
        $importer->record($account, $payload, dispatchEvent: false);
        $importer->record($account, new ChannelMessagePayload('Another?', 'thread-1', 'message-2'), dispatchEvent: false);
        Queue::assertPushed(ReplyToGuestAutomatically::class, 1);
        Http::assertNothingSent();
    }

    public function test_api_opt_in_can_be_disabled_and_does_not_enable_autonomous_manager_sends(): void
    {
        $thread = $this->thread(false);
        $url = "/api/v1/properties/{$thread->property_id}/agent";
        $this->patchJson($url, ['automatic_guest_replies' => true])->assertOk()->assertJsonPath('data.brief.automatic_guest_replies', true);
        $this->assertSame(auth()->id(), $thread->property->fresh()->settings['agent']['automatic_replies_authorized_by']);
        $this->patchJson($url, ['automatic_guest_replies' => false])->assertOk()->assertJsonPath('data.brief.automatic_guest_replies', false);
        $this->assertNull($thread->property->fresh()->settings['agent']['automatic_replies_since']);
        $this->assertFalse(AgentCapability::SendMessage->mayEverBeAutonomous());
    }

    public function test_manually_logged_messages_and_suspended_authority_cannot_send(): void
    {
        $thread = $this->thread();
        app(ConversationService::class)->recordInbound($thread, ['body' => 'Old copied message', 'metadata' => ['logged_by_hand' => true]], dispatchEvent: false);
        Queue::assertNotPushed(ReplyToGuestAutomatically::class);
        $message = $this->inbound($thread);
        auth()->user()->update(['status' => 'suspended']);
        $this->instance(GuestAgent::class, Mockery::mock(GuestAgent::class)->shouldNotReceive('answer')->getMock());
        $this->runReply($message);
        Http::assertNothingSent();
    }
}
