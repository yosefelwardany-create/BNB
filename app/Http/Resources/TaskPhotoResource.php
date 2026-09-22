<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Operations\Models\TaskPhoto
 */
class TaskPhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'checklist_item_id' => $this->checklist_item_id,
            'caption' => $this->caption,

            // Before, during, after or damage. This is what settles a dispute
            // with a guest or an owner, so it is first-class rather than a tag.
            'stage' => $this->stage,

            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes === null ? null : (int) $this->size_bytes,

            // A short-lived signed URL where the disk can issue one, and a
            // route through the API where it cannot. Either way the caller
            // never learns the storage path.
            'url' => $this->url(),

            'uploaded_by_id' => $this->uploaded_by_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
