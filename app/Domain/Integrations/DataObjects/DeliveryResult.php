<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * The outcome of handing one message to a transport.
 *
 * `simulated` is carried on the result, not just on the transport, because it
 * is what the message record stores and what the inbox shows. A message that
 * was recorded locally rather than delivered to a guest must never look like
 * one that was sent.
 */
final class DeliveryResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public readonly bool $successful,
        public readonly bool $simulated,
        public readonly ?string $externalMessageId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $retryable = false,
        public readonly array $data = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function delivered(?string $externalMessageId = null, array $data = []): self
    {
        return new self(true, false, $externalMessageId, data: $data);
    }

    /**
     * Accepted by a local implementation rather than sent to a real recipient.
     *
     * @param  array<string, mixed>  $data
     */
    public static function recordedLocally(?string $externalMessageId = null, array $data = []): self
    {
        return new self(true, true, $externalMessageId, data: $data);
    }

    /**
     * A failure that will not succeed on retry: a missing address, a rejected
     * body, a thread the channel has closed.
     *
     * @param  array<string, mixed>  $data
     */
    public static function permanentFailure(string $code, string $message, array $data = []): self
    {
        return new self(false, false, null, $code, $message, false, $data);
    }

    /**
     * A transient failure: a timeout, a rate limit, a 5xx.
     *
     * @param  array<string, mixed>  $data
     */
    public static function transientFailure(string $code, string $message, array $data = []): self
    {
        return new self(false, false, null, $code, $message, true, $data);
    }

    public function failed(): bool
    {
        return ! $this->successful;
    }

    /**
     * The status to record on the message.
     *
     * A simulated success is `sent`, not `delivered`: nothing confirmed
     * receipt, and claiming delivery would be a lie the inbox then repeats.
     */
    public function messageStatus(): string
    {
        if ($this->failed()) {
            return 'failed';
        }

        return $this->simulated ? 'sent' : 'delivered';
    }
}
