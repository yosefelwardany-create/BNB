<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Messaging\Models\Message
 */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $delivery = $this->metadata['delivery'] ?? null;

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'direction' => $this->direction,
            'transport' => $this->transport,
            'channel' => $this->channel,

            'subject' => $this->subject,
            'body' => $this->body,
            'body_html' => $this->body_html,
            'preview' => $this->preview(),

            'author_type' => $this->author_type,
            'author_name' => $this->author_name,
            'user_id' => $this->user_id,
            'author' => new UserResource($this->whenLoaded('user')),
            'is_internal_note' => (bool) $this->is_internal_note,

            // Provenance. A manager must always be able to tell what a person
            // wrote, what a template produced and what a model drafted.
            'is_ai_generated' => (bool) $this->is_ai_generated,
            'template_id' => $this->template_id,
            'automation_rule_id' => $this->automation_rule_id,

            'status' => $this->status,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'failure_reason' => $this->failure_reason,

            // Whether anything actually left the building. A message recorded
            // by a local transport is marked so here and shown as such, never
            // presented to an operator as delivered.
            'delivery' => $delivery === null ? null : [
                'transport' => $delivery['transport'] ?? null,
                'simulated' => (bool) ($delivery['simulated'] ?? false),
                'reason' => $delivery['reason'] ?? null,
                'fallback_from' => $delivery['fallback_from'] ?? null,
            ],

            'language' => $this->language,
            'attachments' => $this->attachments ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
