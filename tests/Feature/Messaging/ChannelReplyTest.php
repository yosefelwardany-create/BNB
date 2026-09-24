<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\DataObjects\OutboundMessage;
use App\Domain\Integrations\Providers\Messaging\ChannelThreadTransport;
use App\Domain\Listings\Models\Listing;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\ConversationService;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Replying into a channel's own inbox.
 *
 * A guest who wrote through Airbnb expects the answer in Airbnb. Emailing them
 * instead is not a smaller version of the same thing: most OTAs forward
 * nothing, several relay through an alias that expires, and a reply outside
 * the thread is one the channel's own support cannot see when the guest later
 * disputes what they were told.
 *
 * The adapter method has always existed and nothing called it — a reply asked
 * for the `channel` transport, found none registered, and quietly went out by
 * email. These hold the path closed.
 */
class ChannelReplyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'cleaning_fee' => 0,
            'max_occupancy' => 4,
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);

        $this->account = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'airbnb',
            'name' => 'Airbnb — main account',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'commission_basis_points' => 1500,
        ]);
    }

    private function map(): ChannelListing
    {
        return ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $this->account->getKey(),
            'listing_id' => $this->listing->getKey(),
            'property_id' => $this->property->getKey(),
            'external_listing_id' => 'ext-listing-1',
        ]);
    }

    private function thread(array $overrides = []): Conversation
    {
        return $this->app->make(ConversationService::class)->open(array_merge([
            'subject' => 'Late arrival',
            'participant_type' => 'guest',
            'channel' => 'airbnb',
            'channel_account_id' => $this->account->getKey(),
            'external_thread_id' => 'thr-9001',
            'property_id' => $this->property->getKey(),
        ], $overrides));
    }

    // -----------------------------------------------------------------------
    // The path that was missing
    // -----------------------------------------------------------------------

    public function test_a_reply_to_a_channel_thread_goes_through_the_channel(): void
    {
        $this->map();

        $message = $this->app->make(ConversationService::class)->send($this->thread(), [
            'body' => 'We can hold the keys until midnight.',
        ]);

        $delivery = $message->fresh()->metadata['delivery'] ?? [];

        $this->assertSame('channel', $message->fresh()->transport);
        $this->assertSame('channel', $delivery['transport'] ?? null);

        // And it did not silently become an email.
        $this->assertArrayNotHasKey('fallback_from', $delivery);
    }

    public function test_a_simulated_channel_never_claims_the_guest_received_it(): void
    {
        $this->map();

        $message = $this->app->make(ConversationService::class)->send($this->thread(), [
            'body' => 'We can hold the keys until midnight.',
        ]);

        $message = $message->fresh();
        $delivery = $message->metadata['delivery'] ?? [];

        // Airbnb requires a partner agreement, so the adapter is a simulation.
        // The workflow really ran; nothing reached a guest.
        $this->assertTrue($delivery['simulated'] ?? false);
        $this->assertSame('sent', $message->status);
        $this->assertNull($message->delivered_at);
        $this->assertStringContainsString('partner agreement', (string) ($delivery['reason'] ?? ''));
    }

    public function test_the_thread_and_the_listing_reach_the_adapter(): void
    {
        $mapping = $this->map();
        $transport = $this->app->make(ChannelThreadTransport::class);

        $message = new OutboundMessage(
            body: 'Hello',
            channel: 'airbnb',
            externalThreadId: 'thr-9001',
            organizationId: $this->organization->getKey(),
            context: [
                'channel_account_id' => $this->account->getKey(),
                'listing_id' => $this->listing->getKey(),
                'property_id' => $this->property->getKey(),
            ],
        );

        $this->assertTrue($transport->canDeliver($message));
        $this->assertNull($transport->undeliverableReason($message));

        $result = $transport->send($message);

        $this->assertTrue($result->successful);
        $this->assertNotNull($result->externalMessageId);
        $this->assertNotNull($mapping->getKey());
    }

    // -----------------------------------------------------------------------
    // What it refuses, and what it says
    // -----------------------------------------------------------------------

    public function test_an_unmapped_property_falls_back_and_says_which_precondition_failed(): void
    {
        // No mapping created: the thread exists but there is no listing on the
        // channel to reply against.
        $conversation = $this->thread();

        $message = $this->app->make(ConversationService::class)->send($conversation, [
            'body' => 'We can hold the keys until midnight.',
        ]);

        $delivery = $message->fresh()->metadata['delivery'] ?? [];

        $this->assertNotSame('channel', $message->fresh()->transport);

        // "Could not address this recipient" would send somebody looking at
        // the guest's email address. The mapping is what is missing.
        $this->assertStringContainsString('not mapped', (string) ($delivery['fallback_from'] ?? ''));
    }

    public function test_a_channel_that_does_not_carry_messages_says_so(): void
    {
        $ical = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'ical',
            'name' => 'iCal feed',
            'status' => ChannelAccount::STATUS_CONNECTED,
        ]);

        $transport = $this->app->make(ChannelThreadTransport::class);

        $reason = $transport->undeliverableReason(new OutboundMessage(
            body: 'Hello',
            channel: 'ical',
            externalThreadId: 'thr-1',
            organizationId: $this->organization->getKey(),
            context: ['channel_account_id' => $ical->getKey()],
        ));

        // A calendar is not an inbox, and it never will be — so this is a
        // permanent refusal, not something to retry.
        $this->assertStringContainsString('not an inbox', (string) $reason);
    }

    public function test_a_conversation_with_no_thread_is_not_sent_to_a_channel(): void
    {
        $transport = $this->app->make(ChannelThreadTransport::class);

        $message = new OutboundMessage(
            body: 'Hello',
            toEmail: 'guest@example.test',
            organizationId: $this->organization->getKey(),
        );

        $this->assertFalse($transport->canDeliver($message));
        $this->assertStringContainsString(
            'did not come from a channel thread',
            (string) $transport->undeliverableReason($message),
        );
    }

    public function test_a_direct_conversation_still_goes_by_email(): void
    {
        $conversation = $this->app->make(ConversationService::class)->open([
            'subject' => 'Direct enquiry',
            'participant_type' => 'guest',
            'property_id' => $this->property->getKey(),
        ]);

        $message = $this->app->make(ConversationService::class)->send($conversation, [
            'body' => 'Thanks for getting in touch.',
        ]);

        // The channel transport must not become the default route for
        // everything just because it exists.
        $this->assertNotSame('channel', $message->fresh()->transport);
    }

    // -----------------------------------------------------------------------
    // What the settings screen reports
    // -----------------------------------------------------------------------

    public function test_the_transport_reports_itself_as_simulated_while_every_channel_is(): void
    {
        $transport = $this->app->make(ChannelThreadTransport::class);

        $this->assertFalse($transport->isLive());
        $this->assertStringContainsString(
            'partner agreement',
            (string) $transport->simulationReason(),
        );
    }

    public function test_it_says_plainly_when_nothing_is_connected(): void
    {
        $this->account->forceFill(['status' => 'disconnected'])->save();

        $transport = $this->app->make(ChannelThreadTransport::class);

        $this->assertStringContainsString(
            'No channel is connected',
            (string) $transport->simulationReason(),
        );
    }
}
