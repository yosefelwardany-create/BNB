<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Payments\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'expense_date' => $this->expense_date?->toDateString(),
            'category' => $this->category,
            'description' => $this->description,

            // Cost, tax and margin reported separately rather than as one
            // figure. An owner is entitled to see the contractor's real price
            // underneath whatever the manager added to it.
            'amount' => $this->amount()->jsonSerialize(),
            'tax_amount' => $this->taxAmount()->jsonSerialize(),
            'markup_percent' => (float) $this->markup_percent,
            'markup_amount' => $this->markupAmount()->jsonSerialize(),
            'chargeable_amount' => $this->chargeableAmount()->jsonSerialize(),
            'currency' => $this->currency,

            // Who bears it. The single most consequential field here.
            'billable_to' => $this->billable_to,

            'status' => $this->status,
            'is_editable' => $this->isEditable(),
            'is_paid' => (bool) $this->is_paid,

            // Set once swept into a finalised statement, and the reason the
            // record is frozen from then on.
            'owner_statement_id' => $this->owner_statement_id,

            'property_id' => $this->property_id,
            'unit_id' => $this->unit_id,
            'owner_id' => $this->owner_id,
            'task_id' => $this->task_id,
            'vendor_id' => $this->vendor_id,

            'property' => new PropertyResource($this->whenLoaded('property')),
            'vendor' => new VendorResource($this->whenLoaded('vendor')),
            'task' => new TaskResource($this->whenLoaded('task')),

            'approved_at' => $this->approved_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_method' => $this->payment_method,
            'receipt_path' => $this->receipt_path,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
