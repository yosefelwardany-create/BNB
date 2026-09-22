<?php

declare(strict_types=1);

namespace App\Domain\Operations\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Operations\Models\Task;
use Illuminate\Database\Eloquent\Model;

/**
 * The work is done.
 *
 * Carries any follow-up tasks raised from failed checklist items, so a
 * coordinator is told about the broken blind at the same moment they are told
 * the clean is finished.
 */
class TaskCompleted extends AbstractDomainEvent
{
    public const NAME = 'task.completed';

    /**
     * @param  list<Task>  $followUps
     */
    public function __construct(
        public readonly Task $task,
        public readonly array $followUps = [],
    ) {
        parent::__construct();
    }

    public function subject(): ?Model
    {
        return $this->task;
    }

    public function payload(): array
    {
        return TaskPayload::build($this->task) + [
            'checklist_progress' => $this->task->checklistProgress(),
            'follow_up_task_ids' => array_map(
                fn (Task $t): string => $t->getKey(),
                $this->followUps,
            ),
            'raised_follow_ups' => count($this->followUps) > 0,
        ];
    }
}
