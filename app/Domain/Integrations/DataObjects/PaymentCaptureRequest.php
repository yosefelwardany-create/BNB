<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

use App\Support\Money\Money;

/**
 * Take funds, either from a prior authorization or directly.
 */
final class PaymentCaptureRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly Money $amount,
        public readonly string $idempotencyKey,
        /** Reference from a prior authorization; null means charge directly. */
        public readonly ?string $authorizationReference = null,
        public readonly ?string $instrumentToken = null,
        public readonly ?string $customerReference = null,
        public readonly ?string $description = null,
        public readonly array $metadata = [],
    ) {}
}
