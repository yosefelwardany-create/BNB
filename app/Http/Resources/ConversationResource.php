<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Messaging\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'title' => $this->displayTitle(),
            'participant_type' => $this->participant_type,
            'status' => $this->status,
            'priority' => $this->priority,

            'reservation_id' => $this->reservation_id,
            'guest_id' => $this->guest_id,
            'owner_id' => $this->owner_id,
            'property_id' => $this->property_id,
            'listing_id' => $this->listing_id,

            'guest' => new GuestResource($this->whenLoaded('guest')),
            'property' => new PropertyResource($this->whenLoaded('property')),
            'reservation' => new ReservationResource($this->whenLoaded('reservation')),

            'assigned_to_id' => $this->assigned_to_id,
            'team_id' => $this->team_id,
            'assignee' => new UserResource($this->whenLoaded('assignee')),

            'unread_count' => (int) $this->unread_count,
            'messages_count' => (int) $this->messages_count,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_message_preview' => $this->last_message_preview,
            'last_message_direction' => $this->last_message_direction,

            // The inbox's primary sort. Sent as a computed value so the front
            // end never has to reimplement what "waiting" means.
            'is_awaiting_reply' => $this->isAwaitingReply(),
            'minutes_waiting' => $this->minutesWaiting(),

            'first_response_minutes' => $this->first_response_minutes,
            'last_inbound_at' => $this->last_inbound_at?->toIso8601String(),
            'last_outbound_at' => $this->last_outbound_at?->toIso8601String(),
            'snoozed_until' => $this->snoozed_until?->toIso8601String(),

            'channel' => $this->channel,
            'messages' => MessageResource::collection($this->whenLoaded('messages')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
