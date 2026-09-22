<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Messaging\Models\SavedReply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavedReply
 */
class SavedReplyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'shortcut' => $this->shortcut,
            'body' => $this->body,
            'category' => $this->category,
            'times_used' => (int) $this->times_used,
            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
