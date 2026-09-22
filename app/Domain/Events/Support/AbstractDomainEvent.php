<?php

declare(strict_types=1);

namespace App\Domain\Events\Support;

use App\Domain\Events\Contracts\DomainEventContract;
use App\Domain\Events\Listeners\RecordDomainEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Convenience base class for domain events.
 *
 * Subclasses declare a `NAME` constant and implement `payload()`. Anything
 * beyond that (subject, organization, idempotency) has sensible defaults
 * derived from the subject model.
 */
abstract class AbstractDomainEvent implements DomainEventContract
{
    use Dispatchable;

    /** The dotted event name. Subclasses must override. */
    public const NAME = 'domain.event';

    /** When the event occurred, in UTC. */
    public readonly \DateTimeImmutable $occurredAt;

    /**
     * The id of the row this event was appended to the store as.
     *
     * The one mutable thing about an event, and deliberately so. Every
     * listener receives the same object instance, and
     * {@see RecordDomainEvent} runs first, so
     * later listeners — automation, webhooks — can cite the durable row rather
     * than searching for it by shape. Null means the event was dispatched
     * without being stored, which happens in tests.
     */
    private ?string $storedEventId = null;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Called once, by the recorder, immediately after the row is written.
     */
    public function recordedAs(string $id): void
    {
        $this->storedEventId ??= $id;
    }

    public function storedEventId(): ?string
    {
        return $this->storedEventId;
    }

    public function eventName(): string
    {
        return static::NAME;
    }

    public function organizationId(): string
    {
        $subject = $this->subject();

        $organizationId = $subject?->getAttribute('organization_id');

        if (! is_string($organizationId)) {
            throw new \LogicException(sprintf(
                'Event [%s] could not determine its organization; override organizationId().',
                static::class,
            ));
        }

        return $organizationId;
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function idempotencyKey(): ?string
    {
        return null;
    }

    /**
     * Additional non-business metadata (source system, correlation ids).
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [];
    }
}
