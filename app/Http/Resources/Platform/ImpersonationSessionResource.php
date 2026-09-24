<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use App\Domain\Platform\Models\ImpersonationSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The record of a support session.
 *
 * Shaped to be readable by the customer as well as the operator, because this is
 * the thing that answers "who looked at my data, when, and why". Emails rather
 * than ids for the two people involved: an id proves nothing to the person
 * asking.
 *
 * @mixin ImpersonationSession
 */
class ImpersonationSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn (): array => [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
            ]),

            'operator' => $this->whenLoaded('platformUser', fn (): ?array => $this->platformUser === null ? null : [
                'id' => $this->platformUser->id,
                'name' => $this->platformUser->fullName(),
                'email' => $this->platformUser->email,
            ]),

            'viewed_as' => $this->whenLoaded('targetUser', fn (): ?array => $this->targetUser === null ? null : [
                'id' => $this->targetUser->id,
                'name' => $this->targetUser->fullName(),
                'email' => $this->targetUser->email,
            ]),

            'reason' => $this->reason,

            'started_at' => $this->started_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'ended_reason' => $this->ended_reason,
            'is_open' => $this->isOpen(),

            // How much was actually read under it, so a session left open in a
            // tab is distinguishable from one that went through the whole
            // account.
            'request_count' => (int) $this->request_count,

            'ip_address' => $this->ip_address,

            // Stated on every one of these, because the guarantee is the point:
            // a support session cannot change anything.
            'was_read_only' => true,
        ];
    }
}
