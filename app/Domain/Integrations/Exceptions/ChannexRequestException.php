<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Exceptions;

use RuntimeException;

/**
 * A Channex call that did not produce a usable answer.
 *
 * Carries `retryable` because the synchronisation engine keys off it: a 429
 * should back off and try again, a rejected rate plan id never will. That
 * distinction is the adapter's to make, not the engine's, and this is how it
 * travels.
 *
 * `warnings` is the Channex-specific part. Their ARI endpoints answer 200 OK
 * for a message that was *partly* rejected, listing what they refused — so a
 * failure here is sometimes a success with a hole in it, and the hole is the
 * thing somebody needs to see.
 */
class ChannexRequestException extends RuntimeException
{
    /**
     * @param  list<string>  $warnings  What Channex refused inside an otherwise successful answer.
     * @param  array<string, mixed>  $body  What Channex actually returned, for whoever is debugging it.
     */
    public function __construct(
        string $message,
        public readonly ?int $errorCode = null,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfter = null,
        public readonly array $warnings = [],
        public readonly array $body = [],
    ) {
        parent::__construct($message);
    }

    public function isRateLimit(): bool
    {
        return $this->errorCode === 429;
    }
}
