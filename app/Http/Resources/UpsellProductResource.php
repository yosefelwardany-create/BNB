<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Upsells\Models\UpsellProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UpsellProduct
 */
class UpsellProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'kind' => $this->kind,

            'price' => $this->price()->jsonSerialize(),
            'currency' => $this->currency,

            // How the price multiplies out. The single field that decides
            // whether an extra clean is 80 or 560.
            'charge_basis' => $this->charge_basis,
            'is_taxable' => (bool) $this->is_taxable,

            // Null means every property, reported as an empty list so a client
            // does not have to know that null and [] mean the same thing.
            'property_ids' => $this->property_ids ?? [],
            'available_everywhere' => blank($this->property_ids),

            // The two fields that keep the menu honest.
            'lead_time_hours' => (int) $this->lead_time_hours,
            'daily_capacity' => $this->daily_capacity,

            'max_quantity' => (int) $this->max_quantity,
            'requires_approval' => (bool) $this->requires_approval,
            'is_active' => (bool) $this->is_active,
            'position' => (int) $this->position,
            'image_path' => $this->image_path,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
