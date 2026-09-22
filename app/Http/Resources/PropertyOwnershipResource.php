<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Owners\Models\PropertyOwnership;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PropertyOwnership
 */
class PropertyOwnershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'owner_id' => $this->owner_id,
            'property' => new PropertyResource($this->whenLoaded('property')),
            'owner' => new OwnerResource($this->whenLoaded('owner')),

            'ownership_percentage' => (float) $this->ownership_percentage,

            // Whoever statements and correspondence are addressed to when a
            // property has several owners.
            'is_primary' => (bool) $this->is_primary,

            // Null on either side means open-ended. Shares are dated because
            // properties change hands mid-year, and a statement attributes
            // each night using the shares in force on that night.
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'is_current' => $this->isInForceOn(CarbonImmutable::today()),

            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
