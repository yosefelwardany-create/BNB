<?php

declare(strict_types=1);

namespace App\Domain\Operations\Policies;

use App\Domain\Operations\Models\TaskRecurrence;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Recurring work: a weekly garden visit, a quarterly boiler service.
 *
 * A recurrence creates work indefinitely and without anyone pressing anything,
 * so editing one is gated more tightly than creating a single task.
 */
class TaskRecurrencePolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['recurrences.manage', 'tasks.view']);
    }

    public function view(User $user, TaskRecurrence $recurrence): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'recurrences.manage');
    }

    public function update(User $user, TaskRecurrence $recurrence): bool
    {
        return $this->access->allows($user, 'recurrences.manage');
    }

    public function delete(User $user, TaskRecurrence $recurrence): bool
    {
        return $this->access->allows($user, 'recurrences.manage');
    }
}
