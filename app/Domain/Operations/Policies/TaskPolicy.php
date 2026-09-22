<?php

declare(strict_types=1);

namespace App\Domain\Operations\Policies;

use App\Domain\Operations\Models\Task;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Record-level authorization for operational work.
 *
 * The distinction that matters is `tasks.view` against `tasks.view_own`. A
 * housekeeper holds only the latter: they see the jobs they have been given
 * and nothing else. Giving them the former would expose every property's
 * maintenance history, every vendor's cost, and the movements of guests in
 * flats they never visit.
 *
 * "Own" means assigned to them personally *or* to a team they belong to,
 * because a crew is given work collectively and whoever picks it up must be
 * able to open it.
 */
class TaskPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['tasks.view', 'tasks.view_own']);
    }

    public function view(User $user, Task $task): bool
    {
        if ($this->access->allows($user, 'tasks.view') && $this->withinScope($user, $task)) {
            return true;
        }

        return $this->access->allows($user, 'tasks.view_own') && $this->isTheirs($user, $task);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'tasks.create');
    }

    public function update(User $user, Task $task): bool
    {
        return $this->actOn($user, $task, 'tasks.update');
    }

    public function assign(User $user, Task $task): bool
    {
        return $this->access->allows($user, 'tasks.assign') && $this->withinScope($user, $task);
    }

    /**
     * Completing is separate from updating: a checklist signed off is a claim
     * about the state of a property that somebody may later be held to.
     */
    public function complete(User $user, Task $task): bool
    {
        return $this->actOn($user, $task, 'tasks.complete');
    }

    /**
     * Tasks are cancelled, never deleted: the record that a clean was
     * scheduled and called off is part of what happened at that property.
     */
    public function cancel(User $user, Task $task): bool
    {
        return $this->access->allows($user, 'tasks.delete') && $this->withinScope($user, $task);
    }

    /**
     * Whether a user may act on a task, and on which tasks.
     *
     * The permission to act is necessary but not sufficient. A cleaner holds
     * `tasks.update` and `tasks.complete` — they have to, to do their job —
     * but only ever over the work they were given. What widens that to the
     * whole portfolio is `tasks.view`: someone who can already see every task
     * is a coordinator, and restricting their edits to their own jobs would
     * make the role unusable.
     *
     * So: can you act at all, and can you see beyond your own rota? The
     * second question decides the scope of the first.
     */
    private function actOn(User $user, Task $task, string $permission): bool
    {
        if (! $this->access->allows($user, $permission)) {
            return false;
        }

        if ($this->access->allows($user, 'tasks.view')) {
            return $this->withinScope($user, $task);
        }

        return $this->isTheirs($user, $task);
    }

    /**
     * Whether the task is this user's to do.
     */
    private function isTheirs(User $user, Task $task): bool
    {
        if ($task->assigned_to_id === $user->getKey()) {
            return true;
        }

        if ($task->team_id === null) {
            return false;
        }

        return $user->memberships()
            ->where('organization_id', $task->organization_id)
            ->whereHas('teams', fn ($query) => $query->whereKey($task->team_id))
            ->exists();
    }

    /**
     * Property restrictions apply to everything a scoped member can reach.
     */
    private function withinScope(User $user, Task $task): bool
    {
        $allowed = $this->access->restrictedPropertyIds($user);

        return $allowed === null || in_array($task->property_id, $allowed, true);
    }
}
