<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Agents\Models\AgentActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One thing a property's agent did.
 *
 * `is_autonomous` is sent as its own field rather than left to be worked out
 * from the kind, because it is the one an operator is actually scanning for: did
 * anything go to a guest without a person reading it. A screen that had to infer
 * it would eventually infer it wrong.
 *
 * @mixin AgentActivity
 */
class AgentActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AgentActivity $activity */
        $activity = $this->resource;

        return [
            'id' => $activity->getKey(),
            'property_id' => $activity->property_id,
            'kind' => $activity->kind,
            'summary' => $activity->summary,
            'detail' => $activity->detail,
            // Kept as it was at the time: an agent renamed next month should
            // not rewrite what the one called Alex did in October.
            'agent_name' => $activity->agent_name,
            'is_autonomous' => (bool) $activity->is_autonomous,
            'occurred_at' => $activity->occurred_at?->toIso8601String(),
            'ask_id' => $activity->agent_ask_id,
            'reservation_id' => $activity->reservation_id,
            'actor' => $this->whenLoaded('actor', fn (): ?string => $activity->actor?->fullName()),
        ];
    }
}
