<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\Messaging;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\Contracts\ChannelAdapterInterface;
use App\Domain\Integrations\Contracts\MessageTransportInterface;
use App\Domain\Integrations\DataObjects\ChannelMessagePayload;
use App\Domain\Integrations\DataObjects\DeliveryResult;
use App\Domain\Integrations\DataObjects\OutboundMessage;
use App\Domain\Integrations\Registries\ChannelAdapterRegistry;

/**
 * Replying into a channel's own inbox.
 *
 * A guest who wrote through Airbnb expects the answer in Airbnb. Emailing them
 * instead is not a smaller version of the same thing: most OTAs forward
 * nothing, several relay through an alias that expires, and a reply that
 * arrives outside the thread is a reply the channel's own support cannot see
 * when the guest later disputes what they were told.
 *
 * `ChannelAdapterInterface::sendMessage()` has always existed and nothing
 * called it. A reply to a channel thread asked for the `channel` transport,
 * found none registered, and quietly went out by email with a fallback reason
 * on the record. This is that transport.
 *
 * Four things have to be true before a message can go into a thread, and each
 * failure says which one was not:
 *
 *  1. The conversation names a channel and carries the thread id it came with.
 *  2. A connected account for that channel still exists.
 *  3. The channel's adapter supports messaging at all — an iCal feed does not,
 *     and never will.
 *  4. The listing the conversation belongs to is mapped on that account, so
 *     there is something to address the thread against.
 *
 * Whether the adapter behind it is real is a separate question, answered per
 * message on the delivery record. A simulated OTA accepts the message and the
 * record says nothing reached the guest — which is the same promise every
 * other integration point in this product makes.
 */
class ChannelThreadTransport implements MessageTransportInterface
{
    public function __construct(private readonly ChannelAdapterRegistry $adapters) {}

    public function key(): string
    {
        return 'channel';
    }

    public function displayName(): string
    {
        return 'Channel inbox';
    }

    /**
     * Live when at least one connected channel has a real adapter behind it.
     *
     * A single answer for a transport that fronts several channels is
     * necessarily a summary; the per-message truth is on the delivery record,
     * where it is the one that matters.
     */
    public function isLive(): bool
    {
        foreach ($this->connectedChannels() as $channel) {
            if ($this->adapters->has($channel) && $this->adapters->make($channel)->isLive()) {
                return true;
            }
        }

        return false;
    }

    public function simulationReason(): ?string
    {
        if ($this->isLive()) {
            return null;
        }

        $channels = $this->connectedChannels();

        if ($channels === []) {
            return 'No channel is connected, so there is no inbox to reply into.';
        }

        return sprintf(
            'The connected channels (%s) are served by simulated adapters, because each requires a '
            .'commercial partner agreement before its API can be used. Replies are recorded here and '
            .'do not reach the guest.',
            implode(', ', $channels),
        );
    }

    public function canDeliver(OutboundMessage $message): bool
    {
        return $this->resolve($message) instanceof ChannelListing;
    }

    public function undeliverableReason(OutboundMessage $message): ?string
    {
        return $this->refusalFor($message);
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        $refusal = $this->refusalFor($message);

        if ($refusal !== null) {
            // Permanent, not transient: none of these resolve by trying again.
            // A thread with no mapped listing needs somebody to map it.
            return DeliveryResult::permanentFailure('channel_unaddressable', $refusal);
        }

        /** @var ChannelListing $listing */
        $listing = $this->resolve($message);
        $adapter = $this->adapters->make((string) $message->channel);

        $result = $adapter->sendMessage($listing, new ChannelMessagePayload(
            body: $message->body,
            externalThreadId: $message->externalThreadId,
            externalReservationId: $this->reservationReference($message),
            senderName: $message->fromName,
            sentAt: new \DateTimeImmutable,
            attachments: $this->attachmentsFor($message),
        ));

        if ($result->failed()) {
            return $result->retryable
                ? DeliveryResult::transientFailure(
                    (string) $result->errorCode,
                    (string) $result->errorMessage,
                    $result->data,
                )
                : DeliveryResult::permanentFailure(
                    (string) $result->errorCode,
                    (string) $result->errorMessage,
                    $result->data,
                );
        }

        // The adapter's own word on whether anything left the building. A
        // simulated OTA returns success — the workflow really did run — and
        // this is where that stops being mistaken for delivery.
        return $adapter->isLive()
            ? DeliveryResult::delivered($result->externalReference, $result->data)
            : DeliveryResult::recordedLocally($result->externalReference, $result->data);
    }

    /**
     * Which of the four preconditions failed, phrased for an operator.
     */
    private function refusalFor(OutboundMessage $message): ?string
    {
        $channel = $message->channel;

        if ($channel === null || $message->externalThreadId === null) {
            return 'This conversation did not come from a channel thread, so there is nothing to reply into.';
        }

        if (! $this->adapters->has($channel)) {
            return sprintf('No adapter is registered for [%s].', $channel);
        }

        if (! $this->adapters->make($channel)->supports(ChannelAdapterInterface::CAPABILITY_MESSAGING)) {
            return sprintf(
                '%s does not carry messages. An iCal feed is a calendar, not an inbox.',
                $this->adapters->make($channel)->displayName(),
            );
        }

        if ($this->account($message) === null) {
            return sprintf(
                'No connected %s account remains, so the thread cannot be addressed.',
                $channel,
            );
        }

        if (! $this->resolve($message) instanceof ChannelListing) {
            return sprintf(
                'This property is not mapped to a listing on %s, so the thread has no listing to reply against.',
                $channel,
            );
        }

        return null;
    }

    /**
     * The mapped listing this thread belongs to.
     */
    private function resolve(OutboundMessage $message): ?ChannelListing
    {
        $account = $this->account($message);

        if ($account === null || $message->externalThreadId === null) {
            return null;
        }

        $channel = (string) $message->channel;

        if (! $this->adapters->has($channel)
            || ! $this->adapters->make($channel)->supports(ChannelAdapterInterface::CAPABILITY_MESSAGING)) {
            return null;
        }

        $listingId = $message->context['listing_id'] ?? null;
        $propertyId = $message->context['property_id'] ?? null;

        return ChannelListing::query()
            ->where('channel_account_id', $account->getKey())
            ->where('is_active', true)
            ->when(
                $listingId !== null,
                fn ($query) => $query->where('listing_id', $listingId),
                fn ($query) => $query->when(
                    $propertyId !== null,
                    fn ($inner) => $inner->where('property_id', $propertyId),
                ),
            )
            ->first();
    }

    private function account(OutboundMessage $message): ?ChannelAccount
    {
        $accountId = $message->context['channel_account_id'] ?? null;

        return ChannelAccount::query()
            ->connected()
            ->when(
                $accountId !== null,
                fn ($query) => $query->whereKey($accountId),
                // A conversation recorded before the account was linked still
                // names its channel, so fall back to any connected account on
                // it rather than refusing a reply the operator can obviously
                // send.
                fn ($query) => $query->forChannel((string) $message->channel),
            )
            ->first();
    }

    private function reservationReference(OutboundMessage $message): ?string
    {
        $reference = $message->context['external_reservation_id'] ?? null;

        return is_string($reference) ? $reference : null;
    }

    /**
     * @return list<array{url: string, name: string, mime_type?: string}>
     */
    private function attachmentsFor(OutboundMessage $message): array
    {
        $attachments = [];

        foreach ($message->attachments as $attachment) {
            // A channel takes a URL it can fetch. A local path is meaningless
            // to it, so it is dropped rather than sent as a broken link.
            if (isset($attachment['url'], $attachment['name'])) {
                $attachments[] = [
                    'url' => (string) $attachment['url'],
                    'name' => (string) $attachment['name'],
                    'mime_type' => (string) ($attachment['mime_type'] ?? 'application/octet-stream'),
                ];
            }
        }

        return $attachments;
    }

    /**
     * @return list<string>
     */
    private function connectedChannels(): array
    {
        return ChannelAccount::query()
            ->connected()
            ->distinct()
            ->pluck('channel')
            ->map(static fn (mixed $channel): string => (string) $channel)
            ->values()
            ->all();
    }
}
