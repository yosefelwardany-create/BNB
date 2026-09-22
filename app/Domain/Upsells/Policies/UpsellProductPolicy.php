<?php

declare(strict_types=1);

namespace App\Domain\Upsells\Policies;

use App\Domain\Upsells\Models\UpsellProduct;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * The menu of extras.
 *
 * Reading is wide, because an agent on the phone has to be able to tell a
 * guest what is available and what it costs. Changing prices and capacity is
 * `upsells.manage`, which is a commercial decision.
 */
class UpsellProductPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['upsells.manage', 'reservations.view']);
    }

    public function view(User $user, UpsellProduct $product): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'upsells.manage');
    }

    public function update(User $user, UpsellProduct $product): bool
    {
        return $this->create($user);
    }

    /**
     * Withdrawn rather than removed: orders placed under it point at it, and
     * a restrict-on-delete foreign key enforces the same thing at the
     * database.
     */
    public function delete(User $user, UpsellProduct $product): bool
    {
        return $this->create($user);
    }
}
