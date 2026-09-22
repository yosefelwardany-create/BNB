<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Operations\Models\ChecklistTemplate
 */
class ChecklistTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'description' => $this->description,

            // Null means the template is the general one for this kind of
            // work; a value means it is specific to that property and wins
            // over the general one.
            'property_id' => $this->property_id,

            'items' => $this->items ?? [],
            'items_count' => count($this->items ?? []),
            'is_default' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
