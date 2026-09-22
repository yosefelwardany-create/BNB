<?php

declare(strict_types=1);

namespace App\Domain\Payments\Exceptions;

use RuntimeException;

/**
 * A payment operation the platform refused, or the processor declined.
 *
 * Carries the provider's own code where there is one, so a declined card and a
 * refund that exceeds its capture are distinguishable by a caller without
 * parsing prose — and so the interface can tell a guest to try another card
 * rather than showing them an internal message.
 */
class PaymentException extends RuntimeException
{
    /**
     * Named `failureCode` rather than `code`: `Exception` already declares a
     * protected int `$code`, and promoting a differently-typed property of
     * that name is a fatal error at class load — which surfaces as the whole
     * process dying rather than as a useful message.
     */
    public function __construct(
        string $message,
        public readonly ?string $failureCode = null,
        public readonly bool $isRetryable = false,
    ) {
        parent::__construct($message);
    }

    public static function exceedsAuthorization(string $reference): self
    {
        return new self(
            sprintf('Payment %s cannot be captured for more than it was authorised for.', $reference),
            'exceeds_authorization',
        );
    }

    public static function exceedsCapture(string $reference): self
    {
        return new self(
            sprintf('Payment %s cannot be refunded for more than was taken.', $reference),
            'exceeds_capture',
        );
    }

    public static function notCapturable(string $reference, string $status): self
    {
        return new self(
            sprintf('Payment %s is %s and cannot be captured.', $reference, $status),
            'not_capturable',
        );
    }

    public static function declined(string $code, string $message, bool $retryable = false): self
    {
        return new self($message, $code, $retryable);
    }
}
