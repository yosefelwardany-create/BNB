<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Payments\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Refund
 */
class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'payment_id' => $this->payment_id,
            'reservation_id' => $this->reservation_id,
            'amount' => $this->amount()->jsonSerialize(),
            'currency' => $this->currency,
            'status' => $this->status,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'is_simulated' => (bool) $this->is_simulated,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failure_message' => $this->failure_message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
