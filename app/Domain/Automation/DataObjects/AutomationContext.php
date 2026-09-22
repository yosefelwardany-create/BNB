<?php

declare(strict_types=1);

namespace App\Domain\Automation\DataObjects;

use App\Domain\Automation\Services\AutomationEngine;
use App\Domain\Guests\Models\Guest;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything a rule is evaluated and acted upon against.
 *
 * Conditions read `payload` — plain, serialisable data with a stable shape, so
 * a rule keeps meaning the same thing however the models are refactored
 * underneath it. Actions get the resolved records, because creating a task or
 * sending a message needs the real thing.
 *
 * Conditions are deliberately not allowed to reach into the models directly.
 * A rule is written against a vocabulary of named fields, and letting it walk
 * relations would turn every condition into a query whose cost and meaning
 * nobody could predict.
 *
 * For a run executed after a delay, the engine builds this payload from the
 * subject's current state laid over the stored event, so "if the booking is
 * still confirmed" asks about now rather than about the moment the event fired.
 * See {@see AutomationEngine}.
 */
final class AutomationContext
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $organizationId,
        public readonly string $eventName,
        public readonly array $payload,
        public readonly ?Model $subject = null,
        public readonly ?Reservation $reservation = null,
        public readonly ?Property $property = null,
        public readonly ?Guest $guest = null,
        public readonly ?string $domainEventId = null,
    ) {}

    /**
     * The property a rule's property filter is tested against.
     */
    public function propertyId(): ?string
    {
        return $this->property?->getKey()
            ?? ($this->payload['property_id'] ?? null);
    }

    /**
     * The channel a rule's channel filter is tested against.
     */
    public function channel(): ?string
    {
        return $this->payload['source'] ?? $this->payload['channel'] ?? null;
    }

    /**
     * A stable identifier for the thing this run is about, used to build the
     * idempotency key. Falls back to the event name when an event concerns no
     * particular record.
     */
    public function subjectKey(): string
    {
        return $this->subject?->getKey() ?? $this->eventName;
    }

    public function subjectType(): ?string
    {
        return $this->subject?->getMorphClass();
    }
}
