<?php

declare(strict_types=1);

namespace App\Domain\Payments\Policies;

use App\Domain\Payments\Models\Payment;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Money movements.
 *
 * Split three ways on purpose, because they are three different amounts of
 * trust: reading what was taken, taking more, and giving it back. A front-desk
 * agent normally has the first two and not the third — a refund is the one
 * operation here with no technical limit on how much it can cost.
 *
 * Nothing is deletable. A payment record is the platform's side of a
 * conversation with a processor, and deleting it would not un-take the money;
 * it would only make the disagreement unresolvable.
 */
class PaymentPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['payments.view', 'financials.view']);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Authorising, capturing or charging.
     */
    public function charge(User $user, ?Payment $payment = null): bool
    {
        return $this->access->allows($user, 'payments.charge');
    }

    public function create(User $user): bool
    {
        return $this->charge($user);
    }

    /**
     * Giving money back.
     *
     * Its own permission, and deliberately not implied by the ability to
     * charge: the two mistakes are not symmetrical.
     */
    public function refund(User $user, Payment $payment): bool
    {
        return $this->access->allows($user, 'payments.refund');
    }

    /**
     * Releasing an authorisation that was never captured.
     *
     * Treated as a charge-level action rather than a refund: no money has
     * moved, so letting the hold go is not the same decision as sending funds
     * back.
     */
    public function void(User $user, Payment $payment): bool
    {
        return $this->charge($user, $payment);
    }

    /**
     * Never. A payment is a record of what a processor did, and the record
     * outlives every correction made to it.
     */
    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }
}
