<?php

declare(strict_types=1);

namespace App\Domain\Operations\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Operations\Enums\TaskKind;
use App\Domain\Operations\Enums\TaskPriority;
use App\Domain\Operations\Enums\TaskStatus;
use App\Domain\Operations\Events\TaskAssigned;
use App\Domain\Operations\Events\TaskCompleted;
use App\Domain\Operations\Events\TaskCreated;
use App\Domain\Operations\Exceptions\InvalidTaskTransitionException;
use App\Domain\Operations\Models\ChecklistTemplate;
use App\Domain\Operations\Models\Task;
use App\Domain\Operations\Models\TaskChecklistItem;
use App\Domain\Platform\Services\SequenceGenerator;
use App\Domain\Properties\Models\Property;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creating, assigning and completing operational work.
 *
 * Two behaviours here are worth singling out:
 *
 *  - Completion is gated on the checklist. A cleaner cannot mark a turnover
 *    done with items outstanding or a required photograph missing, because
 *    the value of a checklist is entirely in it being enforced.
 *  - A failed inspection item raises follow-up work automatically, linked back
 *    to the item, so a finding cannot quietly go nowhere.
 */
class TaskService
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly SequenceGenerator $sequences,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?ChecklistTemplate $template = null): Task
    {
        $organization = $this->tenancy->organizationOrFail();

        return DB::transaction(function () use ($attributes, $template, $organization): Task {
            $kind = $attributes['kind'] instanceof TaskKind
                ? $attributes['kind']
                : TaskKind::from($attributes['kind'] ?? 'custom');

            $property = $attributes['property'] ?? Property::query()->findOrFail($attributes['property_id']);

            $priority = isset($attributes['priority'])
                ? ($attributes['priority'] instanceof TaskPriority
                    ? $attributes['priority']
                    : TaskPriority::from($attributes['priority']))
                : $kind->defaultPriority();

            $task = new Task;

            $task->fill(collect($attributes)->except(['property', 'kind', 'priority', 'checklist_items'])->all());

            $task->organization_id = $organization->getKey();
            $task->property_id = $property->getKey();
            $task->kind = $kind;
            $task->priority = $priority;
            $task->currency ??= $property->currency;
            $task->billable_to ??= $kind->defaultBillableTo();
            $task->created_by_id = auth()->id();

            // Each kind gets its own readable prefix (CLE-00123, MAI-00045) so
            // a reference on a rota or an invoice says what it is.
            //
            // The sequence key carries the kind. A sequence stores the prefix
            // it was created with and reuses it for every later call, so a
            // single "task" sequence would stamp whichever kind happened to be
            // created first onto all of them — every maintenance ticket
            // numbered CLE-.
            $task->reference = $this->sequences->next(
                $organization->getKey(),
                SequenceGenerator::TASK.':'.$kind->value,
                strtoupper(substr($kind->value, 0, 3)),
                5,
            );

            // An assignment made at creation means the task starts assigned
            // rather than pending.
            if ($task->isAssigned() && $task->status === TaskStatus::Pending) {
                $task->status = TaskStatus::Assigned;
            }

            // A service level follows from the priority unless one was given.
            if ($task->sla_due_at === null && $kind === TaskKind::Maintenance) {
                $task->sla_due_at = now()->addHours($priority->slaHours());
            }

            $task->save();

            $items = $attributes['checklist_items'] ?? null;

            if ($items !== null) {
                $this->attachChecklistItems($task, $items);
            } elseif ($template !== null) {
                $this->applyTemplate($task, $template);
            }

            TaskCreated::dispatch($task);

            return $task;
        });
    }

    /**
     * Copy a checklist template onto a task.
     */
    public function applyTemplate(Task $task, ChecklistTemplate $template): void
    {
        $this->attachChecklistItems($task, $template->items ?? []);
    }

    /**
     * @param  list<array{label: string, section?: string, requires_photo?: bool}>  $items
     */
    public function attachChecklistItems(Task $task, array $items): void
    {
        foreach (array_values($items) as $position => $item) {
            TaskChecklistItem::query()->create([
                'organization_id' => $task->organization_id,
                'task_id' => $task->getKey(),
                'section' => $item['section'] ?? null,
                'label' => $item['label'],
                'position' => $position,
                'requires_photo' => $item['requires_photo'] ?? false,
            ]);
        }
    }

    /**
     * Put somebody on the job.
     */
    public function assign(
        Task $task,
        ?string $userId = null,
        ?string $teamId = null,
        ?string $vendorId = null,
    ): Task {
        return DB::transaction(function () use ($task, $userId, $teamId, $vendorId): Task {
            $previous = [
                'assigned_to_id' => $task->assigned_to_id,
                'team_id' => $task->team_id,
                'vendor_id' => $task->vendor_id,
            ];

            $task->assigned_to_id = $userId;
            $task->team_id = $teamId;
            $task->vendor_id = $vendorId;

            // Reassignment resets acceptance: the new assignee has not agreed
            // to anything yet.
            if ($task->status === TaskStatus::Accepted || $task->status === TaskStatus::Pending) {
                $task->status = $task->isAssigned() ? TaskStatus::Assigned : TaskStatus::Pending;
                $task->accepted_at = null;
            }

            $task->save();

            $this->audit->record(
                action: 'task.assigned',
                subject: $task,
                oldValues: $previous,
                newValues: ['assigned_to_id' => $userId, 'team_id' => $teamId, 'vendor_id' => $vendorId],
                description: sprintf('Task %s reassigned', $task->reference),
            );

            TaskAssigned::dispatch($task);

            return $task;
        });
    }

    /**
     * Move a task through its lifecycle.
     */
    public function transitionTo(Task $task, TaskStatus $target, ?string $reason = null): Task
    {
        if ($task->status === $target) {
            return $task;
        }

        if (! $task->status->canTransitionTo($target)) {
            throw new InvalidTaskTransitionException($task->status, $target);
        }

        if ($target === TaskStatus::Completed) {
            return $this->complete($task);
        }

        return DB::transaction(function () use ($task, $target, $reason): Task {
            $from = $task->status;
            $task->status = $target;

            match ($target) {
                TaskStatus::Accepted => $task->accepted_at = now(),
                TaskStatus::InProgress => $task->started_at ??= now(),
                TaskStatus::Blocked => $task->blocked_reason = $reason,
                TaskStatus::Cancelled => $task->forceFill([
                    'cancelled_at' => now(),
                    'cancellation_reason' => $reason,
                ]),
                default => null,
            };

            $task->save();

            $this->audit->record(
                action: 'task.status_changed',
                subject: $task,
                oldValues: ['status' => $from->value],
                newValues: ['status' => $target->value],
                description: $reason ?? sprintf('Task moved from %s to %s', $from->label(), $target->label()),
            );

            return $task;
        });
    }

    /**
     * Finish a task.
     *
     * Refused while the checklist has outstanding items or a required
     * photograph is missing: a checklist nobody has to complete is decoration.
     *
     * @param  array{actual_minutes?: int, actual_cost?: int, notes?: string}  $completion
     */
    public function complete(Task $task, array $completion = [], bool $force = false): Task
    {
        $outstanding = $this->completionBlockers($task);

        if ($outstanding !== [] && ! $force) {
            throw new InvalidTaskTransitionException(
                $task->status,
                TaskStatus::Completed,
                $outstanding,
            );
        }

        return DB::transaction(function () use ($task, $completion): Task {
            $task->status = TaskStatus::Completed;
            $task->completed_at = now();
            $task->started_at ??= $task->completed_at;

            $task->actual_minutes = $completion['actual_minutes']
                ?? $task->durationMinutes()
                ?? $task->estimated_minutes;

            if (isset($completion['actual_cost'])) {
                $task->actual_cost = $completion['actual_cost'];
            }

            $task->save();

            // A failed item becomes follow-up work, linked back to the finding.
            $followUps = $this->raiseFollowUps($task);

            $this->audit->record(
                action: 'task.completed',
                subject: $task,
                newValues: [
                    'actual_minutes' => $task->actual_minutes,
                    'actual_cost' => $task->actual_cost,
                    'follow_ups' => count($followUps),
                ],
                description: $completion['notes'] ?? sprintf('Task %s completed', $task->reference),
            );

            TaskCompleted::dispatch($task, $followUps);

            return $task;
        });
    }

    /**
     * What stands between a task and being marked done.
     *
     * @return list<string>
     */
    public function completionBlockers(Task $task): array
    {
        $blockers = [];

        foreach ($task->checklistItems()->get() as $item) {
            if ($item->status === TaskChecklistItem::PENDING) {
                $blockers[] = sprintf('"%s" has not been completed.', $item->label);

                continue;
            }

            if ($item->requires_photo && ! $item->canComplete()) {
                $blockers[] = sprintf('"%s" requires a photo.', $item->label);
            }
        }

        return $blockers;
    }

    /**
     * Record the outcome of one checklist item.
     */
    public function completeChecklistItem(
        TaskChecklistItem $item,
        string $status,
        ?string $notes = null,
        ?string $severity = null,
    ): TaskChecklistItem {
        $item->fill([
            'status' => $status,
            'notes' => $notes,
            'severity' => $severity,
            'completed_by_id' => auth()->id(),
            'completed_at' => now(),
        ])->save();

        return $item;
    }

    /**
     * Turn failed checklist items into maintenance work.
     *
     * @return list<Task>
     */
    private function raiseFollowUps(Task $task): array
    {
        $created = [];

        foreach ($task->failedChecklistItems() as $item) {
            if ($item->follow_up_task_id !== null) {
                continue;
            }

            $followUp = $this->create([
                'kind' => TaskKind::Maintenance,
                'title' => sprintf('Follow-up: %s', $item->label),
                'description' => sprintf(
                    "Raised from %s %s.\n\n%s",
                    $task->kind->label(),
                    $task->reference,
                    $item->notes ?? 'No further detail was recorded.',
                ),
                'property_id' => $task->property_id,
                'unit_id' => $task->unit_id,
                'reservation_id' => $task->reservation_id,
                'priority' => match ($item->severity) {
                    'critical' => TaskPriority::Urgent,
                    'major' => TaskPriority::High,
                    'minor' => TaskPriority::Low,
                    default => TaskPriority::Normal,
                },
                'severity' => $item->severity,
                'generated_by' => 'checklist_failure',
                'generation_key' => 'checklist:'.$item->getKey(),
            ]);

            $item->forceFill(['follow_up_task_id' => $followUp->getKey()])->save();

            $created[] = $followUp;
        }

        return $created;
    }
}
