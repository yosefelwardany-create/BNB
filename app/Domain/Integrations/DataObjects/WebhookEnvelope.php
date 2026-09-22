<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

use DateTimeImmutable;

/**
 * A verified inbound webhook, normalised across providers.
 *
 * `providerEventId` is what the platform deduplicates on, so a provider that
 * redelivers an event cannot produce a second business change.
 */
final class WebhookEnvelope
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $type,
        public readonly string $providerEventId,
        public readonly array $data,
        public readonly ?DateTimeImmutable $occurredAt = null,
    ) {}
}
