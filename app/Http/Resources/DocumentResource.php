<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Documents\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'description' => $this->description,

            'mime_type' => $this->mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'size' => $this->humanSize(),
            'checksum' => $this->checksum,

            // Deliberately absent: the storage path and disk. A caller with
            // the path could reason about the storage layout, and the file is
            // only ever reachable through the download endpoint, where the
            // permission is checked on every read.
            'download_url' => route('api.v1.documents.download', ['document' => $this->id]),

            'documentable_type' => $this->documentable_type,
            'documentable_id' => $this->documentable_id,

            'is_guest_visible' => (bool) $this->is_guest_visible,
            'is_owner_visible' => (bool) $this->is_owner_visible,

            // The two facts a privacy review asks about.
            'contains_personal_data' => (bool) $this->contains_personal_data,
            'retention_until' => $this->retention_until?->toDateString(),
            'is_past_retention' => $this->isPastRetention(),

            'expires_on' => $this->expires_on?->toDateString(),
            'has_expired' => $this->hasExpired(),

            'uploaded_by' => $this->whenLoaded('uploader', fn (): ?string => $this->uploader?->fullName()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
