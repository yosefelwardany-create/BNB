<?php

declare(strict_types=1);

namespace App\Domain\Owners\Policies;

use App\Domain\Owners\Models\Owner;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Owners are the manager's clients.
 *
 * Portal access is its own permission rather than part of `owners.update`.
 * Granting a login creates a real account with real visibility into revenue,
 * which is a different decision from correcting somebody's postcode.
 *
 * An owner who holds a portal login is themselves a user here, and must be able
 * to read their own record without being able to read anyone else's.
 */
class OwnerPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, 'owners.view');
    }

    public function view(User $user, Owner $owner): bool
    {
        // An owner reading their own record through the portal.
        if ($owner->user_id === $user->getKey()) {
            return true;
        }

        return $this->access->allows($user, 'owners.view');
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'owners.create');
    }

    public function update(User $user, Owner $owner): bool
    {
        return $this->access->allows($user, 'owners.update');
    }

    /**
     * Seeing an owner's banking details is separate from seeing the owner.
     * Only the people who move money need them, and they are the details most
     * worth stealing.
     */
    public function viewBanking(User $user, Owner $owner): bool
    {
        if ($owner->user_id === $user->getKey()) {
            return true;
        }

        return $this->access->allowsAny($user, ['owners.update', 'owner_payouts.manage']);
    }

    public function managePortal(User $user, Owner $owner): bool
    {
        return $this->access->allows($user, 'owners.portal');
    }

    /**
     * Who owns what decides who is paid what, so editing a share is gated on
     * being able to edit the owner rather than the property.
     */
    public function manageOwnership(User $user, Owner $owner): bool
    {
        return $this->access->allows($user, 'owners.update');
    }
}
