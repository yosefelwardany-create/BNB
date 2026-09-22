<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * The outcome of one synchronisation call against a channel.
 *
 * `retryable` is what the synchronisation engine keys off: a rejected price is
 * a permanent failure a human must look at, whereas a timeout should back off
 * and try again.
 */
final class ChannelSyncResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public readonly bool $successful,
        public readonly ?string $externalReference = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $retryable = false,
        public readonly array $data = [],
    ) {}

    public static function success(?string $externalReference = null, array $data = []): self
    {
        return new self(true, $externalReference, data: $data);
    }

    /**
     * A failure that will not succeed on retry (validation, rejected content,
     * revoked credentials). Surfaced to an operator.
     */
    public static function permanentFailure(string $code, string $message, array $data = []): self
    {
        return new self(false, null, $code, $message, false, $data);
    }

    /**
     * A transient failure (timeout, rate limit, 5xx). Retried with backoff.
     */
    public static function transientFailure(string $code, string $message, array $data = []): self
    {
        return new self(false, null, $code, $message, true, $data);
    }

    public function failed(): bool
    {
        return ! $this->successful;
    }
}
