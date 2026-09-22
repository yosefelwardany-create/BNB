<?php

declare(strict_types=1);

namespace App\Domain\Channels\Policies;

use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * The mapping between one of our listings and one of theirs.
 *
 * `channels.map` is its own permission because a wrong mapping is the single
 * most expensive mistake in distribution: it points a channel's calendar at
 * the wrong apartment, and every booking that follows is sold against
 * inventory that does not exist.
 */
class ChannelListingPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['channels.view', 'channels.manage']);
    }

    public function view(User $user, ChannelListing $mapping): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allowsAny($user, ['channels.map', 'channels.manage']);
    }

    public function update(User $user, ChannelListing $mapping): bool
    {
        return $this->create($user);
    }

    /**
     * Forcing a push now rather than waiting for the scheduler.
     */
    public function sync(User $user, ChannelListing $mapping): bool
    {
        return $this->access->allowsAny($user, ['channels.sync', 'channels.manage']);
    }

    /**
     * Unmapping stops the listing being sold there. The record stays, because
     * bookings already taken through it point at it.
     */
    public function delete(User $user, ChannelListing $mapping): bool
    {
        return $this->access->allowsAny($user, ['channels.map', 'channels.manage']);
    }
}
