<?php

declare(strict_types=1);

namespace App\Domain\Operations\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Operations\Models\Task;
use Illuminate\Database\Eloquent\Model;

class TaskCreated extends AbstractDomainEvent
{
    public const NAME = 'task.created';

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
