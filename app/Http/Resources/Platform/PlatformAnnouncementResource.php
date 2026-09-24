<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use App\Domain\Platform\Models\PlatformAnnouncement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformAnnouncement
 */
class PlatformAnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'level' => $this->level,
            'audience' => $this->audience,
            'organization_ids' => $this->organization_ids ?? [],
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_published' => (bool) $this->is_published,
            'is_dismissible' => (bool) $this->is_dismissible,

            // Whether it is being shown right now, which is not the same as
            // being published: a scheduled notice is published and not yet live.
            'is_live' => $this->isLive(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
