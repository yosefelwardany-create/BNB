<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\DataObjects\ChannelMessagePayload;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConversationService;
use App\Domain\Reservations\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A guest's message on a channel, landing in this platform's inbox.
 *
 * Until now the inbox could only be filled by hand — somebody reading Airbnb in
 * one window and typing it into Habitat in another, which is why the platform
 * carries a `logged_by_hand` flag at all. A channel that can actually hand over
 * its threads changes what the inbox is: the response times become real, the
 * agent has history to read, and a dispute eleven months later has a record.
 *
 * ## What it is careful about
 *
 * **One message, once.** Threads are pulled on a schedule *and* pushed by
 * webhook, so the same message arrives twice as a matter of course. The
 * channel's own message id is what makes the second one a no-op; without it
 * every poll would duplicate the conversation.
 *
 * **Nothing is attributed to us that we did not send.** A thread contains the
 * host's side too, and importing that as though this platform sent it would
 * invent a sending history. Outbound messages are recorded with
 * `was_sent_by_us` false unless we can see our own id on them.
 *
 * **An unmapped listing is reported, not dropped.** A message for a listing
 * nobody mapped is a guest talking to a property this platform cannot name, and
 * the answer is to map it rather than to lose the message.
 */
class ChannelMessageImporter
{
    public function __construct(private readonly ConversationService $conversations) {}

    /**
     * Record one inbound or outbound channel message.
     *
     * Returns null when the message was already known, or when it belongs to a
     * listing nobody has mapped.
     */
    public function record(
        ChannelAccount $account,
        ChannelMessagePayload $payload,
        bool $fromGuest = true,
        bool $dispatchEvent = true,
    ): ?Message {
        if (trim($payload->body) === '' || $payload->externalThreadId === null) {
            return null;
        }

        return DB::transaction(function () use ($account, $payload, $fromGuest, $dispatchEvent): ?Message {
            ChannelAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            /*
             * The channel's own id is the de-duplication key.
             *
             * Checked before anything is created, and inside the transaction,
             * because a poll and a webhook carrying the same message routinely
             * arrive at the same moment.
             */
            if ($payload->externalMessageId !== null) {
                $seen = Message::query()
                    ->where('external_message_id', $payload->externalMessageId)
                    ->whereHas('conversation', fn ($q) => $q->where('channel_account_id', $account->id))
                    ->exists();

                if ($seen) {
                    return null;
                }
            }

            $conversation = $this->conversationFor($account, $payload);

            if ($conversation === null) {
                return null;
            }

            $sentAt = $payload->sentAt === null
                ? CarbonImmutable::now()
                : CarbonImmutable::instance($payload->sentAt);

            if ($fromGuest) {
                return $this->conversations->recordInbound($conversation, [
                    'body' => $payload->body,
                    'channel' => $account->channel,
                    'author_name' => $payload->senderName,
                    'external_message_id' => $payload->externalMessageId,
                    'sent_at' => $sentAt,
                ], dispatchEvent: $dispatchEvent);
            }

            /*
             * The host's own side of the thread.
             *
             * Recorded as delivered elsewhere rather than sent: this platform
             * did not send it, and the one thing the messaging layer refuses to
             * do is claim otherwise. It matters more here than it looks — a
             * reply typed into Airbnb by a colleague and imported as ours would
             * make the agent think it had already answered.
             */
            return $this->conversations->recordDeliveredElsewhere($conversation, [
                'body' => $payload->body,
                'channel' => $account->channel,
                // `channel`, not the `manual` this method assumes: it did go
                // out through the channel, just not from here. "Somebody typed
                // it into Airbnb" and "somebody carried it by hand" are
                // different stories and the thread should tell the true one.
                'transport' => 'channel',
                'author_name' => $payload->senderName,
                'external_message_id' => $payload->externalMessageId,
                'sent_at' => $sentAt,
            ]);
        });
    }

    /**
     * The thread this message belongs to, opening one if it is new.
     */
    private function conversationFor(ChannelAccount $account, ChannelMessagePayload $payload): ?Conversation
    {
        $existing = Conversation::query()
            ->where('channel_account_id', $account->getKey())
            ->where('external_thread_id', $payload->externalThreadId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // The booking is the best anchor there is: it carries the property, the
        // guest and the dates, so a thread attached to one needs nothing
        // inferred.
        $mapping = $this->mappingFor($account, $payload);
        $candidates = $payload->externalReservationId === null
            ? collect()
            : Reservation::query()
                ->where('channel_account_id', $account->getKey())
                ->where(fn ($q) => $q->where('external_reservation_id', $payload->externalReservationId)
                    ->when($account->channel === 'hostex', fn ($q) => $q->orWhere('hostex_reservation_code', $payload->externalReservationId)))
                ->when($mapping?->property_id !== null, fn ($q) => $q->where('property_id', $mapping->property_id))
                ->with(['property', 'listing', 'guest'])
                ->limit(2)->get();
        $reservation = $candidates->count() === 1 ? $candidates->first() : null;

        if ($reservation !== null) {
            return $this->conversations->forReservation($reservation, [
                'channel' => $account->channel,
                'channel_account_id' => $account->getKey(),
                'external_thread_id' => $payload->externalThreadId,
            ]);
        }

        $mapping = $this->mappingFor($account, $payload);

        if ($mapping === null) {
            // A guest is talking to a property this platform cannot name. Worth
            // a line in the log, because the fix is a mapping somebody has to
            // make and nothing else will prompt them.
            Log::warning('A channel message arrived for a listing nobody has mapped.', [
                'channel' => $account->channel,
                'external_thread_id' => $payload->externalThreadId,
                'external_reservation_id' => $payload->externalReservationId,
            ]);

            return null;
        }

        return $this->conversations->open([
            'organization_id' => $account->organization_id,
            'property_id' => $mapping->property_id ?? $mapping->listing?->property_id,
            'listing_id' => $mapping->listing_id,
            'participant_type' => 'guest',
            'channel' => $account->channel,
            'channel_account_id' => $account->getKey(),
            'external_thread_id' => $payload->externalThreadId,
            'subject' => ($payload->attachments['guest_name'] ?? null) ?: $mapping->external_name,
        ]);
    }

    /**
     * Which of our listings this thread is about.
     *
     * Only ever by an explicit mapping. Guessing from a thread's own fields
     * would put a guest's message against the wrong property, and the whole
     * point of the mapping table is that somebody decided this.
     */
    private function mappingFor(ChannelAccount $account, ChannelMessagePayload $payload): ?ChannelListing
    {
        $listingId = $payload->attachments['listing_id']
            ?? $payload->attachments['property_id']
            ?? null;

        if (! is_string($listingId) || trim($listingId) === '') {
            return null;
        }

        return ChannelListing::query()
            ->with('listing')
            ->where('channel_account_id', $account->getKey())
            ->where('external_listing_id', trim($listingId))
            ->whereNotNull('listing_id')
            ->first();
    }
}
