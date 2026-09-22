<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Webhooks\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WebhookDelivery
 */
class WebhookDeliveryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'webhook_endpoint_id' => $this->webhook_endpoint_id,
            'domain_event_id' => $this->domain_event_id,
            'event_name' => $this->event_name,

            'attempt' => (int) $this->attempt,
            'status' => $this->status,

            // What was actually sent, byte for byte. Kept rather than
            // regenerated: regenerating would show the record as it stands
            // now, which settles no signature dispute and misleads once the
            // subject has changed.
            'payload' => $this->payload,

            'response_status' => $this->response_status,
            'response_body' => $this->response_body,
            'duration_ms' => $this->duration_ms,
            'error_message' => $this->error_message,

            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),

            'endpoint' => new WebhookEndpointResource($this->whenLoaded('endpoint')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
