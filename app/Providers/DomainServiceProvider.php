<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Events\Contracts\DomainEventContract;
use App\Domain\Events\Listeners\RecordDomainEvent;
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
    }
}
