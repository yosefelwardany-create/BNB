<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\OwnerAccounting\Models\OwnerStatementLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OwnerStatementLine
 */
class OwnerStatementLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'line_date' => $this->line_date?->toDateString(),
            'description' => $this->description,
            'explanation' => $this->explanation,

            // Signed: positive adds to what the owner is due, negative takes
            // away. The column sums to the statement total with no rules to
            // remember, which is the first thing an owner checks.
            'amount' => $this->amount()->jsonSerialize(),
            'is_deduction' => $this->isDeduction(),

            'ownership_percentage' => (float) $this->ownership_percentage,

            // What the property earned before the share was applied, present
            // only where it differs.
            'full_amount' => $this->fullAmount()?->jsonSerialize(),

            'property_id' => $this->property_id,
            'reservation_id' => $this->reservation_id,
            'expense_id' => $this->expense_id,
        ];
    }
}
