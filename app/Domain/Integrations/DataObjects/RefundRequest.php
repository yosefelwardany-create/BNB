<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

use App\Support\Money\Money;

/**
 * Return funds against a captured payment.
 *
 * The platform validates that the total refunded never exceeds the total
 * captured before this reaches a provider, and providers are expected to
 * enforce the same rule independently.
 */
final class RefundRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $paymentReference,
        public readonly Money $amount,
        public readonly string $idempotencyKey,
        public readonly ?string $reason = null,
        public readonly array $metadata = [],
    ) {}
}
