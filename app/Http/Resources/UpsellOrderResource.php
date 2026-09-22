<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Upsells\Models\UpsellOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UpsellOrder
 */
class UpsellOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,

            'upsell_product_id' => $this->upsell_product_id,
            'reservation_id' => $this->reservation_id,
            'product' => new UpsellProductResource($this->whenLoaded('product')),

            'quantity' => (int) $this->quantity,

            // The price as it was when the guest ordered, not as the product
            // stands today. A guest who ordered a transfer at 40 pays 40.
            'unit_price' => $this->unitPrice()->jsonSerialize(),
            'total_price' => $this->totalPrice()->jsonSerialize(),
            'currency' => $this->currency,

            'status' => $this->status,

            // Whether the guest is being billed for this. A declined or
            // cancelled order is not, and getting it wrong bills somebody for
            // a transfer that never happened.
            'is_chargeable' => $this->isChargeable(),
            'is_settled' => $this->isSettled(),

            'service_date' => $this->service_date?->toDateString(),
            'guest_notes' => $this->guest_notes,
            'internal_notes' => $this->internal_notes,

            // The charge on the booking and the job somebody has to do. Both
            // reported, because an order with a charge and no task is an order
            // nobody delivers.
            'reservation_charge_id' => $this->reservation_charge_id,
            'task_id' => $this->task_id,

            'approved_at' => $this->approved_at?->toIso8601String(),
            'declined_at' => $this->declined_at?->toIso8601String(),
            'declined_reason' => $this->declined_reason,
            'fulfilled_at' => $this->fulfilled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
