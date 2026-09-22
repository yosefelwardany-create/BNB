<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Automation\Listeners\RunAutomationForEvent;
use App\Domain\Channels\Listeners\MarkChannelsDirty;
use App\Domain\Events\Contracts\DomainEventContract;
use App\Domain\Events\Listeners\RecordDomainEvent;
use App\Domain\Operations\Listeners\ScheduleTurnoverForReservation;
use App\Domain\Reservations\Events\ReservationCancelled;
use App\Domain\Reservations\Events\ReservationConfirmed;
use App\Domain\Reservations\Events\ReservationCreated;
use App\Domain\Reservations\Events\ReservationModified;
use App\Domain\Webhooks\Listeners\SendEventToWebhooks;
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

        // Automation reads the same stream, and must be registered *after* the
        // recorder: the recorder hands the event the id of the row it wrote,
        // which automation cites on every run it creates. Registration order is
        // therefore load-bearing, not cosmetic.
        Event::listen(DomainEventContract::class, [RunAutomationForEvent::class, 'handle']);

        // Outbound webhooks read the same stream, and likewise after the
        // recorder: subscribers deduplicate on the stored event id, and the
        // delivery table's unique index uses it to make a redelivered job
        // harmless. Registered without any list of event classes, so adding an
        // event to the platform makes it deliverable without touching this.
        Event::listen(DomainEventContract::class, [SendEventToWebhooks::class, 'handle']);

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

        // Anything that changes what can be sold marks every channel the
        // listing is published to as needing a push. Synchronous but trivial —
        // a flag and a date window — so a slow OTA can never delay, or fail, a
        // guest's booking. The scheduler drains the flags.
        Event::listen(ReservationCreated::class, [MarkChannelsDirty::class, 'handleCreated']);
        Event::listen(ReservationConfirmed::class, [MarkChannelsDirty::class, 'handleConfirmed']);
        Event::listen(ReservationModified::class, [MarkChannelsDirty::class, 'handleModified']);
        Event::listen(ReservationCancelled::class, [MarkChannelsDirty::class, 'handleCancelled']);
    }
}
