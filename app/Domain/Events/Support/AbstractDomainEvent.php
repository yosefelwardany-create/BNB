<?php

declare(strict_types=1);

namespace App\Domain\Events\Support;

use App\Domain\Events\Contracts\DomainEventContract;
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

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
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
