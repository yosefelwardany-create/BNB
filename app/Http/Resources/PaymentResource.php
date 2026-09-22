<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Payments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'direction' => $this->direction,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_colour' => $this->status->colour(),

            'amount' => $this->amount()->jsonSerialize(),
            'captured_amount' => $this->capturedAmount()->jsonSerialize(),
            'refunded_amount' => $this->refundedAmount()->jsonSerialize(),
            'fee_amount' => $this->feeAmount()->jsonSerialize(),
            'net_amount' => $this->netAmount()->jsonSerialize(),
            'refundable_amount' => $this->refundableAmount()->jsonSerialize(),
            'capturable_amount' => $this->capturableAmount()->jsonSerialize(),
            'currency' => $this->currency,

            'method' => $this->method,
            'provider' => $this->provider,

            // Whether anything real happened. Read by every surface that shows
            // a payment, so a simulated processor can never be mistaken for a
            // live one.
            'is_simulated' => (bool) $this->is_simulated,

            // Whether the money is ours to hold. A channel-collected payment
            // is real revenue that never touched our bank.
            'is_collected_by_us' => (bool) $this->is_collected_by_us,

            // Enough to recognise the card on a statement, never enough to use
            // it. The stored token is hidden on the model.
            'instrument_brand' => $this->instrument_brand,
            'instrument_last4' => $this->instrument_last4,

            'reservation_id' => $this->reservation_id,
            'guest_id' => $this->guest_id,
            'property_id' => $this->property_id,
            'reservation' => new ReservationResource($this->whenLoaded('reservation')),

            'authorized_at' => $this->authorized_at?->toIso8601String(),
            'captured_at' => $this->captured_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,

            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),
            'description' => $this->description,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
