<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Automation\Models\AutomationRun
 */
class AutomationRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'automation_rule_id' => $this->automation_rule_id,
            'rule_name' => $this->whenLoaded('rule', fn (): ?string => $this->rule?->name),

            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'domain_event_id' => $this->domain_event_id,

            'status' => $this->status,
            'summary' => $this->summary(),

            // The whole point of the run log: what each condition decided, and
            // what each action actually did. Without these, "why did this
            // guest get that message?" has no answer.
            'condition_results' => $this->condition_results,
            'action_results' => $this->action_results,
            'skip_reason' => $this->skip_reason,
            'error' => $this->error,

            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'attempts' => (int) $this->attempts,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
