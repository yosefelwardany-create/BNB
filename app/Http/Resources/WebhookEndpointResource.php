<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Webhooks\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WebhookEndpoint
 */
class WebhookEndpointResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,

            // Empty means every event. Reported as an empty list rather than
            // null so a client does not have to know that null and [] mean the
            // same thing.
            'events' => $this->events ?? [],
            'receives_all_events' => blank($this->events),

            'status' => $this->status,
            'is_healthy' => $this->isHealthy(),

            // Deliberately absent: the signing secret and any custom headers
            // are encrypted and never leave the server. A secret that can be
            // read back is a secret that can be read by somebody else.
            'has_custom_headers' => $this->headers !== [],

            'consecutive_failures' => (int) $this->consecutive_failures,
            'failure_threshold' => (int) $this->failure_threshold,
            'timeout_seconds' => (int) $this->timeout_seconds,
            'max_attempts' => (int) $this->max_attempts,

            'last_success_at' => $this->last_success_at?->toIso8601String(),
            'last_failure_at' => $this->last_failure_at?->toIso8601String(),
            'last_error' => $this->last_error,

            'deliveries_count' => $this->whenCounted('deliveries'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
