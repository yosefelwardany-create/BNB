<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property \App\Domain\Properties\Models\UnitType $resource
 */
class UnitTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'property_id' => $this->resource->property_id,
            'name' => $this->resource->name,
            'code' => $this->resource->code,
            'description' => $this->resource->description,
            'bedrooms' => (int) $this->resource->bedrooms,
            'bathrooms' => (float) $this->resource->bathrooms,
            'beds' => (int) $this->resource->beds,
            'max_occupancy' => (int) $this->resource->max_occupancy,
            'size_value' => $this->resource->size_value,
            'base_rate' => $this->resource->base_rate,
            'is_active' => $this->resource->is_active,
            'position' => (int) $this->resource->position,
            'units_count' => $this->whenCounted('units'),
        ];
    }
}
