<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Events\Contracts\DomainEventContract;
use App\Domain\Events\Listeners\RecordDomainEvent;
use App\Domain\Operations\Listeners\ScheduleTurnoverForReservation;
use App\Domain\Reservations\Events\ReservationCancelled;
use App\Domain\Reservations\Events\ReservationConfirmed;
use App\Domain\Reservations\Events\ReservationModified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the domain layer together.
 *
 * Domain events are the integration seam of the whole product: a single
 * listener persists every one of them, and the consumers (automation,
 * webhooks, notifications, analytics) read from that stream. Adding a consumer
 * therefore never requires touching the code that produces the event.
 */
class DomainServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Persist every domain event. Registered against the interface so any
        // event implementing the contract is captured without further wiring.
        Event::listen(DomainEventContract::class, [RecordDomainEvent::class, 'handle']);

        $this->registerCrossDomainListeners();
    }

    /**
     * Wires one domain's events to another domain's reactions.
     *
     * These listeners are the product's connective tissue, and they live here
     * rather than inside the domains so that neither side knows about the
     * other: the reservation service has no idea operations exist, and
     * operations does not reach into reservations.
     */
    private function registerCrossDomainListeners(): void
    {
        // Confirming, moving or cancelling a booking keeps its cleaning in
        // step.
        Event::listen(
            ReservationConfirmed::class,
            [ScheduleTurnoverForReservation::class, 'handleConfirmed'],
        );

        Event::listen(
            ReservationModified::class,
            [ScheduleTurnoverForReservation::class, 'handleModified'],
        );

        Event::listen(
            ReservationCancelled::class,
            [ScheduleTurnoverForReservation::class, 'handleCancelled'],
        );
    }
}
