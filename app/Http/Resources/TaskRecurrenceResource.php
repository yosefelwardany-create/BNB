<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Operations\Models\TaskRecurrence;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskRecurrence
 */
class TaskRecurrenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'property_id' => $this->property_id,
            'unit_id' => $this->unit_id,

            'frequency' => $this->frequency,
            'interval' => (int) $this->interval,
            'days_of_week' => $this->days_of_week ?? [],
            'day_of_month' => $this->day_of_month,
            'time_of_day' => $this->time_of_day,

            'checklist_template_id' => $this->checklist_template_id,
            'team_id' => $this->team_id,
            'assigned_to_id' => $this->assigned_to_id,
            'vendor_id' => $this->vendor_id,
            'estimated_minutes' => $this->estimated_minutes,

            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'last_generated_on' => $this->last_generated_on?->toDateString(),
            'is_active' => (bool) $this->is_active,

            // The next few dates this will produce work on, computed rather
            // than stored, so an editor can show the effect of a change before
            // it is saved.
            'next_occurrences' => array_map(
                static fn ($date): string => $date->toDateString(),
                array_slice(
                    $this->occurrencesBetween(
                        CarbonImmutable::today(),
                        CarbonImmutable::today()->addMonths(3),
                    ),
                    0,
                    5,
                ),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
