<?php

declare(strict_types=1);

namespace App\Domain\Operations\Listeners;

use App\Domain\Operations\Services\TurnoverScheduler;
use App\Domain\Reservations\Events\ReservationCancelled;
use App\Domain\Reservations\Events\ReservationConfirmed;
use App\Domain\Reservations\Events\ReservationModified;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Keeps cleaning in step with the booking calendar.
 *
 * This is where the product stops being a set of separate modules: confirming
 * a booking creates the clean, moving it moves the clean, and cancelling it
 * cancels the clean — without the reservation service knowing that operations
 * exist.
 *
 * Queued, because a guest confirming a booking should not wait for a cleaning
 * task to be written, and a failure here must not roll back the booking.
 */
class ScheduleTurnoverForReservation implements ShouldQueue
{
    public int $tries = 3;

    /**
     * Queue selection is a property rather than a constructor call: a listener
     * is not a job and has no queue-interaction methods of its own.
     */
    public string $queue = 'default';

    public function __construct(
        private readonly TurnoverScheduler $scheduler,
        private readonly TenantContext $tenancy,
    ) {}

    public function handleConfirmed(ReservationConfirmed $event): void
    {
        $this->inTenant($event->reservation, function () use ($event): void {
            $this->scheduler->scheduleTurnover($event->reservation);
        });
    }

    public function handleModified(ReservationModified $event): void
    {
        // Only a date or unit change affects the clean; a guest adding a
        // toddler does not need the rota touched.
        $payload = $event->payload();

        if (! ($payload['dates_changed'] ?? false) && ! ($payload['unit_changed'] ?? false)) {
            return;
        }

        $this->inTenant($event->reservation, function () use ($event): void {
            $this->scheduler->rescheduleFor($event->reservation);
        });
    }

    public function handleCancelled(ReservationCancelled $event): void
    {
        $this->inTenant($event->reservation, function () use ($event): void {
            $this->scheduler->rescheduleFor($event->reservation);
        });
    }

    /**
     * Jobs run without an ambient tenant, so the organization is rebuilt from
     * the record the event carries before anything tenant-scoped is touched.
     */
    private function inTenant(object $reservation, \Closure $callback): void
    {
        $organization = $reservation->organization;

        if ($organization === null) {
            Log::warning('Skipping turnover scheduling: the reservation has no organization.', [
                'reservation_id' => $reservation->getKey(),
            ]);

            return;
        }

        $this->tenancy->runAs($organization, $callback);
    }
}
