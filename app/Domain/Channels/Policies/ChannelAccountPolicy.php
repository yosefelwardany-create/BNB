<?php

declare(strict_types=1);

namespace App\Domain\Channels\Policies;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Connections to booking channels.
 *
 * Connecting is separated from syncing because they carry different risk. A
 * revenue manager triggering a push is asking the platform to do what it
 * already does on a schedule; somebody changing the commission rate or the
 * credentials is changing what every future booking is worth and who can take
 * one.
 *
 * Disconnecting is never deletion. An account holds the history of every
 * booking that came through it and every mapping that depends on it, so it is
 * marked disconnected and left in place.
 */
class ChannelAccountPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['channels.view', 'channels.manage']);
    }

    public function view(User $user, ChannelAccount $account): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'channels.manage');
    }

    public function update(User $user, ChannelAccount $account): bool
    {
        return $this->access->allows($user, 'channels.manage');
    }

    /**
     * Asking the channel whether the credentials still work.
     */
    public function verify(User $user, ChannelAccount $account): bool
    {
        return $this->access->allowsAny($user, ['channels.manage', 'channels.sync']);
    }

    /**
     * Pushing availability and rates.
     */
    public function sync(User $user, ChannelAccount $account): bool
    {
        return $this->access->allowsAny($user, ['channels.sync', 'channels.manage']);
    }

    /**
     * Stop trading on a channel without losing what it sent us.
     */
    public function disconnect(User $user, ChannelAccount $account): bool
    {
        return $this->access->allows($user, 'channels.manage');
    }

    public function delete(User $user, ChannelAccount $account): bool
    {
        return false;
    }
}
