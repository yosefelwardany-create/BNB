<?php

declare(strict_types=1);

namespace App\Domain\Reservations\Policies;

use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Record-level authorization for reservations.
 *
 * Cancelling and reinstating are separate permissions from editing: changing a
 * guest's note is routine, releasing sold inventory is not.
 */
class ReservationPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, 'reservations.view');
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $this->access->allows($user, 'reservations.view')
            && $this->withinScope($user, $reservation);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'reservations.create');
    }

    public function update(User $user, Reservation $reservation): bool
    {
        return $this->access->allows($user, 'reservations.update')
            && $this->withinScope($user, $reservation);
    }

    public function cancel(User $user, Reservation $reservation): bool
    {
        return $this->access->allows($user, 'reservations.cancel')
            && $this->withinScope($user, $reservation);
    }

    public function reinstate(User $user, Reservation $reservation): bool
    {
        return $this->access->allows($user, 'reservations.reinstate')
            && $this->withinScope($user, $reservation);
    }

    public function checkIn(User $user, Reservation $reservation): bool
    {
        return $this->access->allows($user, 'reservations.checkin')
            && $this->withinScope($user, $reservation);
    }

    private function withinScope(User $user, Reservation $reservation): bool
    {
        $allowed = $this->access->restrictedPropertyIds($user);

        return $allowed === null || in_array($reservation->property_id, $allowed, true);
    }
}
