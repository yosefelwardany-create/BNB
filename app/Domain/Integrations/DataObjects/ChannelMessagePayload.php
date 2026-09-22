<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * A guest message travelling to or from a channel's own inbox.
 */
final class ChannelMessagePayload
{
    /**
     * @param  list<array{url: string, name: string, mime_type?: string}>  $attachments
     */
    public function __construct(
        public readonly string $body,
        public readonly ?string $externalThreadId = null,
        public readonly ?string $externalMessageId = null,
        public readonly ?string $externalReservationId = null,
        public readonly ?string $senderName = null,
        public readonly ?\DateTimeImmutable $sentAt = null,
        public readonly array $attachments = [],
    ) {}
}
