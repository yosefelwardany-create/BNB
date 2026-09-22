<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * The outcome of programming or revoking a door code.
 */
final class AccessCodeResult
{
    private function __construct(
        public readonly bool $successful,
        public readonly ?string $externalCodeId = null,
        public readonly ?string $code = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $retryable = false,
    ) {}

    public static function issued(string $externalCodeId, string $code): self
    {
        return new self(true, $externalCodeId, $code);
    }

    public static function revoked(string $externalCodeId): self
    {
        return new self(true, $externalCodeId);
    }

    public static function failure(string $code, string $message, bool $retryable = false): self
    {
        return new self(false, null, null, $code, $message, $retryable);
    }
}
