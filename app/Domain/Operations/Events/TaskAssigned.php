<?php

declare(strict_types=1);

namespace App\Domain\Operations\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Operations\Models\Task;
use Illuminate\Database\Eloquent\Model;

/**
 * Somebody has been put on the job. Notifications to the assignee hang off
 * this rather than being sent from the assignment code itself.
 */
class TaskAssigned extends AbstractDomainEvent
{
    public const NAME = 'task.assigned';

    public function __construct(public readonly Task $task)
    {
        parent::__construct();
    }

    public function subject(): ?Model
    {
        return $this->task;
    }

    public function payload(): array
    {
        return TaskPayload::build($this->task);
    }
}
