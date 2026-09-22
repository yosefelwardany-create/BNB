<?php

declare(strict_types=1);

namespace App\Domain\OwnerAccounting\Policies;

use App\Domain\OwnerAccounting\Models\OwnerPayout;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Money sent to owners.
 *
 * One permission governs raising, settling and cancelling a payout, because
 * all three are the same decision made at different moments. What is *not*
 * governed by permission is finality: a payout already sent cannot be
 * cancelled or re-paid by anybody, which is enforced in the service rather
 * than here — a role should never be the thing standing between an operator
 * and a double payment.
 */
class OwnerPayoutPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['owner_payouts.manage', 'financials.view'])
            || $user->ownerId() !== null;
    }

    public function view(User $user, OwnerPayout $payout): bool
    {
        // An owner can see what they were sent. Nothing else about the payout
        // run is theirs to read.
        $ownerId = $user->ownerId();

        if ($ownerId !== null) {
            return $ownerId === $payout->owner_id;
        }

        return $this->access->allowsAny($user, ['owner_payouts.manage', 'financials.view']);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'owner_payouts.manage');
    }

    public function update(User $user, OwnerPayout $payout): bool
    {
        return ! $payout->isPaid() && $this->create($user);
    }

    /**
     * Recording that the transfer actually happened.
     */
    public function settle(User $user, OwnerPayout $payout): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, OwnerPayout $payout): bool
    {
        return false;
    }
}
