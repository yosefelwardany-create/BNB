<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Agents\Models\AgentAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One thing the agent proposes to do, as the approval screen needs it.
 *
 * `consequence` travels with every row rather than living in the frontend,
 * because the sentence a person reads before pressing Approve is part of the
 * safety design and not decoration. A screen that showed "Block nights" with no
 * mention of what a blocked night costs would be a worse screen, and keeping
 * that text next to the capability means there is one copy of it.
 *
 * @mixin AgentAction
 */
class AgentActionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'reservation_id' => $this->reservation_id,
            'conversation_id' => $this->conversation_id,

            'capability' => $this->capability->value,
            'capability_label' => $this->capability->label(),
            'consequence' => $this->capability->consequence(),

            'summary' => $this->summary,
            'arguments' => $this->arguments,

            'status' => $this->status,
            // Whether this still deserves a decision. Not derivable from the
            // status alone: a proposal past its expiry is still `proposed` until
            // something sweeps it, and must not offer a button in the meantime.
            'is_open' => $this->isOpen(),
            'was_autonomous' => (bool) $this->was_autonomous,

            // Null when nobody asked — which is what autonomous means, and the
            // reason it is worth being able to see on the row.
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),

            'outcome' => $this->outcome,
            'external_reference' => $this->external_reference,

            'expires_at' => $this->expires_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'executed_at' => $this->executed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
