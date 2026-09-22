<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Api\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApiKey
 */
class ApiKeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            // The prefix is not the key and cannot be used as one. It is here
            // so a list of four keys can be read by a human deciding which is
            // the one on the staging server.
            'prefix' => $this->prefix,

            // The key itself appears exactly once, in the response to the
            // request that created it. Only a hash is stored, so there is
            // nothing to return here even if somebody wanted it.
            'abilities' => $this->abilities ?? [],
            'allowed_ips' => $this->allowed_ips ?? [],
            'rate_limit_per_minute' => (int) $this->rate_limit_per_minute,

            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'last_used_ip' => $this->last_used_ip,
            'request_count' => (int) $this->request_count,

            'expires_at' => $this->expires_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_reason' => $this->revoked_reason,
            'is_usable' => $this->isUsable(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
