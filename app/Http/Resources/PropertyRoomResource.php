<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Properties\Models\PropertyRoom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property PropertyRoom $resource
 */
class PropertyRoomResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'name' => $this->resource->name,
            'room_type' => $this->resource->room_type,
            'position' => (int) $this->resource->position,
            'beds' => $this->resource->beds ?? [],
            'bed_count' => $this->resource->bedCount(),
            'has_ensuite' => $this->resource->has_ensuite,
            'unit_id' => $this->resource->unit_id,
        ];
    }
}
