<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Operations\Models\Team
 */
class TeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'kind' => $this->kind,
            'description' => $this->description,
            'color' => $this->color,
            'is_active' => (bool) $this->is_active,
            'members_count' => $this->whenCounted('members'),
            'open_tasks_count' => $this->whenCounted('tasks'),
            'members' => $this->whenLoaded('members', fn () => $this->members->map(fn ($membership): array => [
                'membership_id' => $membership->id,
                'user_id' => $membership->user_id,
                'is_lead' => (bool) $membership->pivot->is_lead,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
