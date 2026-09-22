<?php

declare(strict_types=1);

namespace App\Domain\Upsells\Policies;

use App\Domain\Upsells\Models\UpsellOrder;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Orders for extras.
 *
 * Deciding an order is gated on reservations rather than on `upsells.manage`,
 * because accepting one charges a guest and raises work — that is a booking
 * decision made by whoever is looking after the stay, not a commercial one
 * made by whoever sets the prices.
 */
class UpsellOrderPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['reservations.view', 'upsells.manage']);
    }

    public function view(User $user, UpsellOrder $order): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allowsAny($user, ['reservations.update', 'upsells.manage']);
    }

    /**
     * Accepting or refusing. Accepting charges the guest, so it needs the
     * authority to change a booking.
     */
    public function decide(User $user, UpsellOrder $order): bool
    {
        return $this->create($user);
    }

    public function update(User $user, UpsellOrder $order): bool
    {
        return $this->create($user);
    }

    /**
     * Cancelled, never deleted: a guest who asked for something and was told
     * no will refer to it.
     */
    public function delete(User $user, UpsellOrder $order): bool
    {
        return false;
    }
}
