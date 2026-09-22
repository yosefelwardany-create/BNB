<?php

declare(strict_types=1);

namespace App\Domain\Events\Contracts;

use App\Domain\Events\Listeners\RecordDomainEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * Every domain event in the platform implements this contract.
 *
 * Implementations are plain, immutable value objects. They are dispatched
 * through Laravel's event bus (so listeners can react in-process) *and*
 * appended to the `domain_events` table by
 * {@see RecordDomainEvent}, which gives automation
 * and webhooks a durable, replayable stream.
 */
interface DomainEventContract
{
    /**
     * The dotted event name, e.g. `reservation.confirmed`.
     */
    public function eventName(): string;

    /**
     * The organization the event belongs to.
     */
    public function organizationId(): string;

    /**
     * The primary record the event concerns, if any.
     */
    public function subject(): ?Model;

    /**
     * Serialisable data describing the event. This is what automation
     * conditions evaluate against and what webhook subscribers receive, so it
     * must not contain Eloquent models or secrets.
     *
     * @return array<string, mixed>
     */
    public function payload(): array;

    /**
     * Optional deduplication key. When present, a second event with the same
     * key in the same organization is discarded.
     */
    public function idempotencyKey(): ?string;
}
