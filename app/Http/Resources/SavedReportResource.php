<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Reports\Models\SavedReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavedReport
 */
class SavedReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'report_key' => $this->report_key,
            'parameters' => $this->parameters ?? [],

            'is_shared' => (bool) $this->is_shared,
            'is_active' => (bool) $this->is_active,

            'schedule_cron' => $this->schedule_cron,
            'schedule_timezone' => $this->schedule_timezone,
            'is_scheduled' => $this->isScheduled(),

            // Reported because a schedule with no recipients runs and produces
            // nothing anybody sees, which looks exactly like one that is
            // broken.
            'recipients' => $this->recipients ?? [],
            'has_recipients' => $this->hasRecipients(),

            'format' => $this->format,

            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'next_run_at' => $this->next_run_at?->toIso8601String(),
            'run_count' => (int) $this->run_count,
            'last_error' => $this->last_error,

            'created_by_id' => $this->created_by_id,
            'created_by' => $this->whenLoaded('creator', fn (): ?string => $this->creator?->fullName()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
