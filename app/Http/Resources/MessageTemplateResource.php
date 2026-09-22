<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Messaging\Models\MessageTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MessageTemplate
 */
class MessageTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'category' => $this->category,

            'subject' => $this->subject,
            'body' => $this->body,

            'language' => $this->language,
            'translation_of_id' => $this->translation_of_id,
            'translations' => MessageTemplateResource::collection($this->whenLoaded('translations')),

            'transport' => $this->transport,

            // Null means every property; a list scopes the template.
            'property_ids' => $this->property_ids,
            'channels' => $this->channels,

            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
