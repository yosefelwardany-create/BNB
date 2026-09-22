<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property User $resource
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'first_name' => $this->resource->first_name,
            'last_name' => $this->resource->last_name,
            'name' => $this->resource->fullName(),
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'timezone' => $this->resource->timezone,
            'locale' => $this->resource->locale,
            'avatar_path' => $this->resource->avatar_path,
            'status' => $this->resource->status->value,
            'email_verified' => $this->resource->email_verified_at !== null,
            'mfa_enabled' => $this->resource->mfa_enabled,
            'last_login_at' => $this->resource->last_login_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
