<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Operations\Models\TaskChecklistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskChecklistItem
 */
class TaskChecklistItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'section' => $this->section,
            'label' => $this->label,
            'position' => $this->position,
            'status' => $this->status,
            'requires_photo' => (bool) $this->requires_photo,
            'notes' => $this->notes,
            'severity' => $this->severity,

            // Whether the photo requirement has actually been met, rather than
            // whether one is required: the app needs to know if pressing
            // "done" will be refused before the cleaner presses it.
            'can_complete' => $this->canComplete(),

            'completed_by_id' => $this->completed_by_id,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'follow_up_task_id' => $this->follow_up_task_id,
            'photos' => TaskPhotoResource::collection($this->whenLoaded('photos')),
        ];
    }
}
