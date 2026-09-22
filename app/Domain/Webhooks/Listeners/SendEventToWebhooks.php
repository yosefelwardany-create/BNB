<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Listeners;

use App\Domain\Events\Contracts\DomainEventContract;
use App\Domain\Webhooks\Services\WebhookDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Feeds the domain event stream to subscribers.
 *
 * This listener is the entire outbound integration surface: adding an event to
 * the platform makes it deliverable without touching anything here, because it
 * listens to the contract rather than to a list of event classes.
 *
 * It must never be able to fail the thing that raised the event. A booking is
 * confirmed whether or not somebody's CRM is reachable, so everything here is
 * wrapped and logged — the work itself is queued, and the retry sweep is the
 * safety net for anything that does not make it that far.
 */
class SendEventToWebhooks
{
    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
        private readonly TenantContext $tenancy,
    ) {}

    public function handle(DomainEventContract $event): void
    {
        try {
            $this->dispatcher->dispatch(
                $event->eventName(),
                $event->payload(),
                // Present once the recorder has written the event, which is
                // why this listener is registered after it. Receivers use it
                // to deduplicate, and the partial unique index on deliveries
                // uses it to make a redelivered job harmless.
                $event->storedEventId(),
            );
        } catch (\Throwable $exception) {
            Log::error('Could not queue webhooks for a domain event.', [
                'event' => $event->eventName(),
                'organization_id' => $this->tenancy->id(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
