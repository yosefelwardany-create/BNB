<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Operations\Models\TaskComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskComment
 */
class TaskCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'body' => $this->body,
            'is_internal' => (bool) $this->is_internal,
            'user_id' => $this->user_id,
            'author' => new UserResource($this->whenLoaded('user')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
