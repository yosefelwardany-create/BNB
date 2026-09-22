<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * A message handed to a transport for delivery.
 *
 * Deliberately a flat value object rather than the Eloquent model: a transport
 * should be able to send something that has not been persisted (a test send
 * from the template editor), and it has no business reading the rest of the
 * database through a model's relations.
 */
final class OutboundMessage
{
    /**
     * @param  list<array{url?: string, path?: string, name: string, mime_type?: string}>  $attachments
     * @param  array<string, mixed>  $context  Transport-specific routing hints — a
     *                                         channel thread id, a reservation reference.
     */
    public function __construct(
        public readonly string $body,
        public readonly ?string $subject = null,
        public readonly ?string $bodyHtml = null,
        public readonly ?string $toEmail = null,
        public readonly ?string $toPhone = null,
        public readonly ?string $toName = null,
        public readonly ?string $fromName = null,
        public readonly ?string $replyTo = null,
        public readonly ?string $channel = null,
        public readonly ?string $externalThreadId = null,
        public readonly ?string $messageId = null,
        public readonly ?string $organizationId = null,
        public readonly ?string $language = null,
        public readonly array $attachments = [],
        public readonly array $context = [],
    ) {}

    /**
     * A short description of who this is going to, for logs and for the
     * delivery record. Never the full body.
     */
    public function recipientDescription(): string
    {
        return $this->toEmail
            ?? $this->toPhone
            ?? ($this->externalThreadId !== null
                ? sprintf('%s thread %s', $this->channel ?? 'channel', $this->externalThreadId)
                : ($this->toName ?? 'unknown recipient'));
    }
}
