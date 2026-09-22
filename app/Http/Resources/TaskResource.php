<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Operations\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'title' => $this->title,
            'description' => $this->description,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_colour' => $this->status->colour(),
            'priority' => $this->priority->value,

            // What the interface may offer, decided by the enum rather than by
            // the front end guessing at the lifecycle.
            'allowed_transitions' => array_map(
                static fn ($status): string => $status->value,
                $this->status->allowedTransitions(),
            ),

            'property_id' => $this->property_id,
            'unit_id' => $this->unit_id,
            'reservation_id' => $this->reservation_id,
            'property' => new PropertyResource($this->whenLoaded('property')),
            'unit' => new UnitResource($this->whenLoaded('unit')),

            'assigned_to_id' => $this->assigned_to_id,
            'team_id' => $this->team_id,
            'vendor_id' => $this->vendor_id,
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'team' => new TeamResource($this->whenLoaded('team')),
            'vendor' => new VendorResource($this->whenLoaded('vendor')),
            'is_assigned' => $this->isAssigned(),

            'scheduled_start' => $this->scheduled_start?->toIso8601String(),
            'scheduled_end' => $this->scheduled_end?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'sla_due_at' => $this->sla_due_at?->toIso8601String(),

            // The same instant in the property's own clock. Sent alongside the
            // absolute value rather than instead of it, because a coordinator
            // in another country needs both.
            'local_scheduled_start' => $this->relationLoaded('property')
                ? $this->localScheduledStart()?->toIso8601String()
                : null,

            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),

            'estimated_minutes' => $this->estimated_minutes,
            'actual_minutes' => $this->actual_minutes,
            'is_overdue' => $this->isOverdue(),
            'breaches_sla' => $this->breachesSla(),

            'estimated_cost' => $this->estimatedCost()?->jsonSerialize(),
            'actual_cost' => $this->actualCost()?->jsonSerialize(),
            'currency' => $this->currency,
            'billable_to' => $this->billable_to,
            'is_billed' => (bool) $this->is_billed,

            'issue_category' => $this->issue_category,
            'severity' => $this->severity,
            'affects_availability' => (bool) $this->affects_availability,
            'blocked_reason' => $this->blocked_reason,
            'cancellation_reason' => $this->cancellation_reason,

            'generated_by' => $this->generated_by,
            'checklist_progress' => $this->when(
                $this->relationLoaded('checklistItems'),
                fn (): float => $this->checklistProgress(),
            ),
            'checklist_items' => TaskChecklistItemResource::collection(
                $this->whenLoaded('checklistItems'),
            ),
            'comments' => TaskCommentResource::collection($this->whenLoaded('comments')),
            'photos' => TaskPhotoResource::collection($this->whenLoaded('photos')),

            'tags' => $this->when(
                $this->relationLoaded('tags'),
                fn (): array => $this->tagNames(),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
