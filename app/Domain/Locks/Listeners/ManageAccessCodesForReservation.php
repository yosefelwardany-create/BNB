<?php

declare(strict_types=1);

namespace App\Domain\Locks\Listeners;

use App\Domain\Locks\Services\AccessCodeManager;
use App\Domain\Reservations\Events\ReservationCancelled;
use App\Domain\Reservations\Events\ReservationModified;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Keeps door codes in step with the booking.
 *
 * Cancellation is the case that matters, and it is why this listener exists.
 * A guest whose booking was cancelled and whose code still works can walk into
 * a property that has been re-let — which is a considerably worse outcome than
 * any amount of missing revenue.
 *
 * Moving a booking is handled by revoking and reissuing rather than by editing
 * the window, because a provider that accepted the first code may refuse the
 * second and the platform must find that out now, while somebody can still
 * act on it.
 *
 * Nothing here is allowed to fail the reservation. A lock that is unreachable
 * must not be able to stop a cancellation from being recorded — the booking is
 * cancelled either way, and the scheduled sweep is the safety net for the
 * code.
 */
class ManageAccessCodesForReservation
{
    public function __construct(
        private readonly AccessCodeManager $codes,
        private readonly TenantContext $tenancy,
    ) {}

    public function handleCancelled(ReservationCancelled $event): void
    {
        $this->safely($event->reservation, function (Reservation $reservation): void {
            $this->codes->revokeForReservation($reservation, 'The booking was cancelled.');
        });
    }

    public function handleModified(ReservationModified $event): void
    {
        $payload = $event->payload();

        // A guest adding a toddler does not change when the door should open.
        if (! ($payload['dates_changed'] ?? false) && ! ($payload['unit_changed'] ?? false)) {
            return;
        }

        $this->safely($event->reservation, function (Reservation $reservation): void {
            // Revoked and reissued rather than adjusted: a provider that
            // accepted the first code may refuse the second, and finding that
            // out now is the entire point.
            $this->codes->revokeForReservation($reservation, 'The booking was changed.');
            $this->codes->issueForReservation($reservation);
        });
    }

    private function safely(Reservation $reservation, \Closure $work): void
    {
        $organization = $reservation->organization;

        if ($organization === null) {
            return;
        }

        try {
            $this->tenancy->runAs($organization, fn () => $work($reservation));
        } catch (\Throwable $exception) {
            // An unreachable lock must never be able to stop a cancellation
            // from being recorded.
            Log::error('Could not update access codes for a reservation.', [
                'reservation_id' => $reservation->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
