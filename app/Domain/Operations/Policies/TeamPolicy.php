<?php

declare(strict_types=1);

namespace App\Domain\Operations\Policies;

use App\Domain\Operations\Models\Team;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Staff crews.
 *
 * Membership of a team decides what work a person can see, so editing teams is
 * an access-control decision as much as an operational one and is gated on
 * `teams.manage` rather than on the task permissions.
 */
class TeamPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['teams.view', 'tasks.view', 'tasks.assign']);
    }

    public function view(User $user, Team $team): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'teams.manage');
    }

    public function update(User $user, Team $team): bool
    {
        return $this->access->allows($user, 'teams.manage');
    }

    public function delete(User $user, Team $team): bool
    {
        return $this->access->allows($user, 'teams.manage');
    }
}
