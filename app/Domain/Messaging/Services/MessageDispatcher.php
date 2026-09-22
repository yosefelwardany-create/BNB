<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Integrations\Contracts\MessageTransportInterface;
use App\Domain\Integrations\DataObjects\DeliveryResult;
use App\Domain\Integrations\DataObjects\OutboundMessage;
use App\Domain\Integrations\Registries\MessageTransportRegistry;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;

/**
 * Decides how an outbound message travels, and records what happened.
 *
 * Routing is a preference order, not a single choice:
 *
 *   1. The transport the message asks for, if it can address the recipient.
 *   2. The configured default, if it can.
 *   3. The local log, which always can.
 *
 * Falling back is never silent. The message keeps the transport that actually
 * carried it, the result records whether anything real happened, and a
 * fallback records why the preferred route could not be used — so "the guest
 * never got it" is answerable from the message row alone.
 */
class MessageDispatcher
{
    public function __construct(private readonly MessageTransportRegistry $transports) {}

    /**
     * Send a persisted message and write the outcome back onto it.
     */
    public function dispatch(Message $message): DeliveryResult
    {
        // An internal note is a conversation between colleagues. It has no
        // recipient outside the building and must never reach one.
        if ($message->is_internal_note) {
            return DeliveryResult::recordedLocally(null, ['reason' => 'internal note']);
        }

        $outbound = $this->compose($message);
        $preferred = $message->transport ?: $this->transports->default()->key();

        [$transport, $fallbackReason] = $this->route($preferred, $outbound);

        $result = $transport->send($outbound);

        $this->recordOutcome($message, $transport, $result, $fallbackReason);

        return $result;
    }

    /**
     * Pick a transport, explaining any deviation from what was asked for.
     *
     * @return array{0: MessageTransportInterface, 1: string|null}
     */
    private function route(string $preferred, OutboundMessage $outbound): array
    {
        if ($this->transports->has($preferred)) {
            $transport = $this->transports->make($preferred);

            if ($transport->canDeliver($outbound)) {
                return [$transport, null];
            }

            $reason = sprintf(
                'The %s transport could not address this recipient.',
                $transport->displayName(),
            );
        } else {
            // A conversation that came from a channel asks for that channel's
            // own transport. Until the channel connection exists, there is no
            // thread to reply into and saying so is the honest outcome.
            $reason = sprintf('No transport is registered for [%s].', $preferred);
        }

        $default = $this->transports->default();

        if ($default->key() !== $preferred && $default->canDeliver($outbound)) {
            return [$default, $reason];
        }

        return [$this->transports->make('local'), $reason];
    }

    /**
     * Turn a persisted message into the flat object a transport receives.
     */
    private function compose(Message $message): OutboundMessage
    {
        $conversation = $message->conversation;
        $guest = $conversation?->guest;
        $owner = $conversation?->owner;
        $organization = $conversation?->organization;

        return new OutboundMessage(
            body: $message->body,
            subject: $message->subject ?? $conversation?->subject,
            bodyHtml: $message->body_html,
            toEmail: $guest?->email ?? $owner?->email,
            toPhone: $guest?->phone ?? $owner?->phone,
            toName: $guest?->fullName() ?? $owner?->display_name,
            fromName: $organization?->name,
            replyTo: $organization?->contact_email,
            channel: $conversation?->channel,
            externalThreadId: $conversation?->external_thread_id,
            messageId: $message->getKey(),
            organizationId: $message->organization_id,
            language: $message->language,
            attachments: $message->attachments ?? [],
            context: [
                'reservation_id' => $conversation?->reservation_id,
                'property_id' => $conversation?->property_id,
            ],
        );
    }

    /**
     * Write the delivery outcome onto the message.
     *
     * The transport that actually carried it is stored, not the one that was
     * requested: a record of an intention is no use when someone asks where a
     * guest's arrival instructions went.
     */
    private function recordOutcome(
        Message $message,
        MessageTransportInterface $transport,
        DeliveryResult $result,
        ?string $fallbackReason,
    ): void {
        $metadata = $message->metadata ?? [];

        $metadata['delivery'] = array_filter([
            'transport' => $transport->key(),
            'live' => $transport->isLive(),
            'simulated' => $result->simulated,
            'reason' => $result->simulated ? $transport->simulationReason() : null,
            'fallback_from' => $fallbackReason,
            'external_id' => $result->externalMessageId,
        ], static fn (mixed $value): bool => $value !== null);

        $message->forceFill([
            'transport' => $transport->key(),
            'status' => $result->messageStatus(),
            'external_message_id' => $result->externalMessageId ?? $message->external_message_id,
            'sent_at' => $message->sent_at ?? now(),
            'delivered_at' => $result->successful && ! $result->simulated ? now() : null,
            'failed_at' => $result->failed() ? now() : null,
            'failure_reason' => $result->errorMessage,
            'metadata' => $metadata,
        ])->save();
    }
}
