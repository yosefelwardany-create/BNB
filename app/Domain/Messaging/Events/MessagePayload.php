<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Events;

use App\Domain\Messaging\Models\Message;

/**
 * The shape message events publish.
 *
 * Shared between the inbound and outbound events so automation conditions and
 * webhook subscribers see the same fields whichever way the message travelled.
 *
 * The body is included because automation legitimately matches on it ("if the
 * guest mentions 'late check-in'"), but nothing here is a secret: attachments
 * are referenced by name and count rather than by a URL that would outlive the
 * event.
 */
final class MessagePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Message $message): array
    {
        $conversation = $message->conversation;

        return [
            'message_id' => $message->getKey(),
            'conversation_id' => $message->conversation_id,
            'direction' => $message->direction,
            'transport' => $message->transport,
            'channel' => $message->channel,
            'body' => $message->body,
            'subject' => $message->subject,
            'author_type' => $message->author_type,
            'author_name' => $message->author_name,
            'is_internal_note' => (bool) $message->is_internal_note,
            'is_ai_generated' => (bool) $message->is_ai_generated,
            'language' => $message->language,
            'attachments_count' => count($message->attachments ?? []),
            'status' => $message->status,

            // Thread context: what a rule almost always wants to test against.
            'reservation_id' => $conversation?->reservation_id,
            'guest_id' => $conversation?->guest_id,
            'owner_id' => $conversation?->owner_id,
            'property_id' => $conversation?->property_id,
            'participant_type' => $conversation?->participant_type,
            'conversation_status' => $conversation?->status,
            'assigned_to_id' => $conversation?->assigned_to_id,
            'minutes_waiting' => $conversation?->minutesWaiting(),
        ];
    }
}
