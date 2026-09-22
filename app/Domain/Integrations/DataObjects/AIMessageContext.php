<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * Everything a model is given about a conversation.
 *
 * Assembled explicitly rather than by handing over whole records, so that what
 * leaves the platform is auditable and contains no payment details or
 * unrelated guest history.
 *
 * @phpstan-type Turn array{role: string, body: string, sent_at?: string}
 */
final class AIMessageContext
{
    /**
     * @param  list<array{role: string, body: string, sent_at?: string}>  $messages
     * @param  array<string, mixed>  $reservation
     * @param  array<string, mixed>  $property
     */
    public function __construct(
        public readonly array $messages,
        public readonly array $reservation = [],
        public readonly array $property = [],
        public readonly ?string $guestName = null,
        public readonly ?string $guestLanguage = null,
        public readonly ?string $organizationVoice = null,
    ) {}

    public function lastGuestMessage(): ?string
    {
        foreach (array_reverse($this->messages) as $message) {
            if (($message['role'] ?? '') === 'guest') {
                return $message['body'];
            }
        }

        return null;
    }
}
