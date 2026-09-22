<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property \App\Domain\Properties\Models\Unit $resource
 */
class UnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $unit = $this->resource;

        return [
            'id' => $unit->getKey(),
            'property_id' => $unit->property_id,
            'unit_type_id' => $unit->unit_type_id,
            'parent_unit_id' => $unit->parent_unit_id,
            'name' => $unit->name,
            'code' => $unit->code,
            'display_name' => $unit->displayName(),
            'floor' => $unit->floor,
            'status' => $unit->status->value,
            'status_label' => $unit->status->label(),
            'is_bookable' => $unit->is_bookable,
            'is_sellable' => $unit->isSellable(),
            'position' => (int) $unit->position,

            // Resolved values show what actually applies after inheriting from
            // the unit type and the property; the raw values show what this
            // unit has overridden itself.
            'resolved' => [
                'bedrooms' => $unit->bedrooms(),
                'bathrooms' => $unit->bathrooms(),
                'beds' => $unit->beds(),
                'max_occupancy' => $unit->maxOccupancy(),
                'base_rate' => $unit->baseRate()->jsonSerialize(),
            ],
            'overrides' => [
                'bedrooms' => $unit->getRawOriginal('bedrooms'),
                'bathrooms' => $unit->getRawOriginal('bathrooms'),
                'beds' => $unit->getRawOriginal('beds'),
                'max_occupancy' => $unit->getRawOriginal('max_occupancy'),
                'base_rate' => $unit->getRawOriginal('base_rate'),
            ],

            'internal_notes' => $this->when(
                $request->user()?->can('units.update') ?? false,
                $unit->internal_notes,
            ),

            'unit_type' => new UnitTypeResource($this->whenLoaded('unitType')),
            'created_at' => $unit->created_at?->toIso8601String(),
        ];
    }
}
