<?php

declare(strict_types=1);

namespace App\Domain\Automation\DataObjects;

use App\Domain\Guests\Models\Guest;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything a rule is evaluated and acted upon against.
 *
 * Conditions read `payload` — plain, serialisable data, so a rule written
 * today still evaluates against an event replayed from the store in a year,
 * whatever has happened to the models since. Actions get the resolved records,
 * because creating a task or sending a message needs the real thing.
 *
 * The two are deliberately not the same: letting conditions reach into models
 * would make rules depend on the current state of the database rather than on
 * what actually happened, and a replay would then give a different answer.
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
