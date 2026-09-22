<?php

declare(strict_types=1);

namespace App\Domain\Automation\Listeners;

use App\Domain\Automation\Services\AutomationEngine;
use App\Domain\Events\Contracts\DomainEventContract;
use App\Domain\Events\Support\AbstractDomainEvent;
use Illuminate\Support\Facades\Log;

/**
 * The bridge from the event stream to automation.
 *
 * Registered against the event contract rather than against individual events,
 * so a new event becomes automatable by existing rules the moment it is
 * dispatched — nothing here changes.
 *
 * Synchronous on purpose, unlike the work it schedules. Deciding *whether* a
 * rule applies is a query and an insert, and doing it inside the transaction
 * that produced the event means the decision commits with the business change:
 * a booking that confirms and an automation run that was created for it can
 * never disagree. Carrying the decision out is a separate, queued job.
 */
class RunAutomationForEvent
{
    public function __construct(private readonly AutomationEngine $engine) {}

    public function handle(DomainEventContract $event): void
    {
        try {
            $this->engine->handleEvent(
                $event,
                $event instanceof AbstractDomainEvent ? $event->storedEventId() : null,
            );
        } catch (\Throwable $exception) {
            // Automation must never be able to fail the business action that
            // triggered it. A rule that cannot be scheduled is a problem for
            // an operator to see in the logs, not a reason a guest's booking
            // fails to confirm.
            Log::error('Automation could not be scheduled for an event.', [
                'event' => $event->eventName(),
                'organization_id' => $event->organizationId(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
