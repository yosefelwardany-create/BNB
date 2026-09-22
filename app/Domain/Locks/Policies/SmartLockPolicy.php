<?php

declare(strict_types=1);

namespace App\Domain\Locks\Policies;

use App\Domain\Locks\Models\SmartLock;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Doors.
 *
 * `locks.view` covers seeing that a lock exists, whether it is online and what
 * its battery is doing — which a cleaner arriving at a property legitimately
 * needs. `locks.manage` covers issuing and revoking codes, and unlocking a
 * door remotely, which is the ability to let somebody into a building.
 */
class SmartLockPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['locks.view', 'locks.manage']);
    }

    public function view(User $user, SmartLock $lock): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'locks.manage');
    }

    public function update(User $user, SmartLock $lock): bool
    {
        return $this->create($user);
    }

    /**
     * Issuing or revoking a code, and opening a door from a distance.
     */
    public function operate(User $user, SmartLock $lock): bool
    {
        return $this->access->allows($user, 'locks.manage');
    }

    /**
     * Never removed while codes point at it: the record of who could open
     * which door, and when, is the answer to the only question anybody asks
     * after an incident.
     */
    public function delete(User $user, SmartLock $lock): bool
    {
        return false;
    }
}
