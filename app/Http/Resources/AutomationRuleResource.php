<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Automation\Models\AutomationRule
 */
class AutomationRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,

            'trigger_type' => $this->trigger_type,
            'trigger_event' => $this->trigger_event,
            'trigger_anchor' => $this->trigger_anchor,
            'trigger_offset_minutes' => $this->trigger_offset_minutes,
            'trigger_time_of_day' => $this->trigger_time_of_day,

            // "3 days before check-in at 10:00 local time", so a rule list is
            // readable without decoding an offset in minutes.
            'trigger_description' => $this->triggerDescription(),

            'conditions' => $this->conditions,
            'actions' => $this->actions,
            'delay_minutes' => (int) $this->delay_minutes,

            'property_ids' => $this->property_ids,
            'channels' => $this->channels,

            'is_active' => (bool) $this->is_active,
            'times_run' => (int) $this->times_run,
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'runs' => AutomationRunResource::collection($this->whenLoaded('runs')),

            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
