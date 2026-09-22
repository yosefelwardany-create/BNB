<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Channels\Models\SyncJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SyncJob
 */
class SyncJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'direction' => $this->direction,
            'status' => $this->status,

            'channel_account_id' => $this->channel_account_id,
            'channel_listing_id' => $this->channel_listing_id,

            'range_start' => $this->range_start?->toDateString(),
            'range_end' => $this->range_end?->toDateString(),

            'records_sent' => (int) $this->records_sent,
            'records_received' => (int) $this->records_received,
            'records_failed' => (int) $this->records_failed,

            'error_code' => $this->error_code,
            'error_message' => $this->error_message,

            // Whether another attempt could succeed. A rejected price needs a
            // person; a timeout does not.
            'is_retryable' => (bool) $this->is_retryable,
            'attempts' => (int) $this->attempts,
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),

            // Whether the adapter that ran this talks to a real system. A
            // report of "synchronisation succeeded" must not conceal that
            // nothing left the building.
            'is_simulated' => (bool) $this->is_simulated,

            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms === null ? null : (int) $this->duration_ms,

            'payload_summary' => $this->payload_summary,
            'result' => $this->result,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
