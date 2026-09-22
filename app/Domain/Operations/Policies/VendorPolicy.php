<?php

declare(strict_types=1);

namespace App\Domain\Operations\Policies;

use App\Domain\Operations\Models\Vendor;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Contractors.
 *
 * Reading the vendor list is allowed to anyone who can see tasks, because
 * dispatching work means knowing who exists. Changing it — rates, insurance
 * dates, bank-adjacent details — needs `vendors.manage`.
 */
class VendorPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['vendors.manage', 'tasks.view']);
    }

    public function view(User $user, Vendor $vendor): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'vendors.manage');
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return $this->access->allows($user, 'vendors.manage');
    }

    /**
     * Deactivated rather than deleted: a vendor is referenced by the history
     * of every job they did and every expense they raised.
     */
    public function delete(User $user, Vendor $vendor): bool
    {
        return $this->access->allows($user, 'vendors.manage');
    }
}
