<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Properties\Models\PropertyPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property PropertyPhoto $resource
 */
class PropertyPhotoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'url' => $this->resource->url(),
            'caption' => $this->resource->caption,
            'room_type' => $this->resource->room_type,
            'position' => (int) $this->resource->position,
            'is_cover' => $this->resource->is_cover,
            'width' => $this->resource->width,
            'height' => $this->resource->height,
            'unit_id' => $this->resource->unit_id,
        ];
    }
}
