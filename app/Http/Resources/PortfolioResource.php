<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Properties\Models\Portfolio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Portfolio $resource
 */
class PortfolioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'description' => $this->resource->description,
            'color' => $this->resource->color,
            'is_active' => $this->resource->is_active,
            'properties_count' => $this->whenCounted('properties'),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
