<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Exceptions;

use RuntimeException;

/**
 * A Hostex call that did not produce a usable answer.
 *
 * Carries `retryable` because the synchronisation engine keys off it: a rate
 * limit should back off and try again, a rejected listing id never will. That
 * distinction is the adapter's to make, not the engine's, and this is how it
 * travels.
 */
class HostexRequestException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $body  What Hostex actually returned, for the screen of whoever is debugging it.
     */
    public function __construct(
        string $message,
        /** Hostex's own error code, which is in the body rather than the status line. */
        public readonly ?int $errorCode = null,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfter = null,
        public readonly array $body = [],
    ) {
        parent::__construct($message);
    }

    public function isRateLimit(): bool
    {
        return $this->errorCode === 429;
    }
}
