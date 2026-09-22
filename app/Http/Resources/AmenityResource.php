<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Properties\Models\Amenity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Amenity $resource
 */
class AmenityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'key' => $this->resource->key,
            'name' => $this->resource->name,
            'category' => $this->resource->category,
            'is_highlight' => $this->resource->is_highlight,
            // Only platform amenities can be published to a channel; the
            // interface uses this to explain why a custom one will not appear
            // on an OTA listing.
            'is_mappable' => $this->resource->isMappable(),
            'value' => $this->whenPivotLoaded('amenity_property', fn () => $this->resource->pivot->value),
        ];
    }
}
