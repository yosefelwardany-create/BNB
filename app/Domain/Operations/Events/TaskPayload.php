<?php

declare(strict_types=1);

namespace App\Domain\Operations\Events;

use App\Domain\Operations\Models\Task;

/**
 * The shape task events publish, shared so automation and webhooks see the
 * same fields whatever happened to the task.
 */
final class TaskPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Task $task): array
    {
        return [
            'task_id' => $task->getKey(),
            'reference' => $task->reference,
            'kind' => $task->kind->value,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'property_id' => $task->property_id,
            'unit_id' => $task->unit_id,
            'reservation_id' => $task->reservation_id,
            'assigned_to_id' => $task->assigned_to_id,
            'team_id' => $task->team_id,
            'vendor_id' => $task->vendor_id,
            'scheduled_start' => $task->scheduled_start?->toIso8601String(),
            'due_at' => $task->due_at?->toIso8601String(),
            'estimated_minutes' => $task->estimated_minutes,
            'actual_minutes' => $task->actual_minutes,
            'actual_cost' => $task->actual_cost === null ? null : (int) $task->actual_cost,
            'currency' => $task->currency,
            'billable_to' => $task->billable_to,
            'is_overdue' => $task->isOverdue(),
        ];
    }
}
