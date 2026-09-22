<?php

declare(strict_types=1);

namespace App\Domain\Events\Listeners;

use App\Domain\Events\Contracts\DomainEventContract;
use App\Domain\Events\Models\DomainEvent;
use App\Domain\Events\Support\AbstractDomainEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Appends every dispatched domain event to the durable event store.
 *
 * This runs synchronously inside the same transaction as the business change
 * so that the stream can never diverge from the data: if the reservation
 * commits, its event committed with it.
 */
class RecordDomainEvent
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function handle(DomainEventContract $event): ?DomainEvent
    {
        $user = Auth::user();

        $attributes = [
            'organization_id' => $event->organizationId(),
            'name' => $event->eventName(),
            'subject_type' => $event->subject()?->getMorphClass(),
            'subject_id' => $event->subject()?->getKey(),
            'payload' => $event->payload(),
            'metadata' => $event instanceof AbstractDomainEvent ? ($event->metadata() ?: null) : null,
            'actor_id' => $user?->getAuthIdentifier(),
            'actor_type' => $user !== null ? 'user' : 'system',
            'idempotency_key' => $event->idempotencyKey(),
            'occurred_at' => $event instanceof AbstractDomainEvent
                ? $event->occurredAt
                : now(),
        ];

        // Writing the event must not be blocked by the tenant scope: events are
        // frequently raised by jobs acting on a tenant other than the ambient
        // one (for example a cross-tenant channel poller).
        return $this->tenancy->withoutScope(function () use ($attributes): ?DomainEvent {
            try {
                return DomainEvent::query()->create($attributes);
            } catch (QueryException $exception) {
                // A unique violation on (organization_id, idempotency_key)
                // means the event was already recorded — that is the point of
                // the key, so treat it as success.
                if ($this->isUniqueViolation($exception)) {
                    return DomainEvent::query()
                        ->withoutGlobalScope('organization')
                        ->where('organization_id', $attributes['organization_id'])
                        ->where('idempotency_key', $attributes['idempotency_key'])
                        ->first();
                }

                Log::error('Failed to record domain event', [
                    'event' => $attributes['name'],
                    'exception' => $exception->getMessage(),
                ]);

                throw $exception;
            }
        });
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23505', '23000'], true);
    }
}
