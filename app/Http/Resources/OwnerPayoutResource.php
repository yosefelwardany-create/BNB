<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\OwnerAccounting\Models\OwnerPayout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OwnerPayout
 */
class OwnerPayoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'owner_id' => $this->owner_id,
            'owner_statement_id' => $this->owner_statement_id,
            'owner' => new OwnerResource($this->whenLoaded('owner')),
            'statement' => new OwnerStatementResource($this->whenLoaded('statement')),

            'amount' => $this->amount()->jsonSerialize(),
            'currency' => $this->currency,

            'status' => $this->status,
            'is_paid' => $this->isPaid(),
            'method' => $this->method,
            'external_reference' => $this->external_reference,

            // Where the money went, as it stood when it went. Already masked
            // on the way in — there is no account number here to leak.
            'destination' => $this->destination_snapshot,

            'scheduled_for' => $this->scheduled_for?->toDateString(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'failure_message' => $this->failure_message,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
