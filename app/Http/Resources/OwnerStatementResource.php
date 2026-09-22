<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OwnerStatement
 */
class OwnerStatementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $money = fn (string $field): array => Money::of((int) $this->{$field}, $this->currency)->jsonSerialize();

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'owner_id' => $this->owner_id,
            'property_id' => $this->property_id,
            'owner' => new OwnerResource($this->whenLoaded('owner')),
            'property' => new PropertyResource($this->whenLoaded('property')),

            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'currency' => $this->currency,

            // Every figure stored rather than recomputed, so a statement sent
            // in March still says in June exactly what the owner read.
            'gross_revenue' => $money('gross_revenue'),
            'accommodation_revenue' => $money('accommodation_revenue'),
            'fee_revenue' => $money('fee_revenue'),
            'taxes_collected' => $money('taxes_collected'),
            'channel_commission' => $money('channel_commission'),
            'payment_fees' => $money('payment_fees'),
            'management_fee' => $money('management_fee'),
            'expenses_total' => $money('expenses_total'),
            'adjustments_total' => $money('adjustments_total'),
            'net_due' => $money('net_due'),
            'reserve_withheld' => $money('reserve_withheld'),
            'opening_balance' => $money('opening_balance'),
            'closing_balance' => $money('closing_balance'),
            'payout_amount' => $money('payout_amount'),

            'nights_sold' => (int) $this->nights_sold,
            'reservations_count' => (int) $this->reservations_count,

            'status' => $this->status,
            'is_editable' => $this->isEditable(),

            // A period that ended owing the manager money. Carried forward
            // rather than invoiced back, so it matters to how this is shown.
            'is_in_deficit' => $this->isInDeficit(),

            'approved_at' => $this->approved_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),

            // The terms this statement was built under, kept so a later
            // renegotiation cannot appear to restate it.
            'agreement_snapshot' => $this->agreement_snapshot,

            'lines' => OwnerStatementLineResource::collection($this->whenLoaded('lines')),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
