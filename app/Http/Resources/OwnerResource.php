<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Owners\Models\Owner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Owner
 */
class OwnerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'company_name' => $this->company_name,
            'display_name' => $this->display_name,

            'email' => $this->email,
            'phone' => $this->phone,
            'country_code' => $this->country_code,
            'language' => $this->language,
            'timezone' => $this->timezone,

            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,

            'tax_identifier' => $this->tax_identifier,
            'vat_number' => $this->vat_number,

            'payout_currency' => $this->payout_currency,
            'payout_method' => $this->payout_method,

            // Masked always, and only present at all for callers who may see
            // them. The full values never leave the server: they are read
            // inside the payout process and nowhere else.
            'banking' => $this->when(
                $request->user() !== null && $request->user()->can('viewBanking', $this->resource),
                fn (): array => $this->maskedBankDetails(),
            ),

            'statement_frequency' => $this->statement_frequency,
            'statement_day' => (int) $this->statement_day,
            'reserve_amount' => (int) $this->reserve_amount,

            'status' => $this->status,
            'portal_enabled' => (bool) $this->portal_enabled,
            'user_id' => $this->user_id,

            'properties_count' => $this->whenCounted('ownerships'),
            'ownerships' => PropertyOwnershipResource::collection($this->whenLoaded('ownerships')),
            'agreements' => ManagementAgreementResource::collection($this->whenLoaded('agreements')),

            'tags' => $this->when(
                $this->relationLoaded('tags'),
                fn (): array => $this->tagNames(),
            ),

            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
