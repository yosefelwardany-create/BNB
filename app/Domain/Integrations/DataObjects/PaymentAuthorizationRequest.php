<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

use App\Support\Money\Money;

/**
 * Ask a provider to hold funds without taking them.
 *
 * `idempotencyKey` is mandatory: a retried authorization must never place a
 * second hold on a guest's card.
 */
final class PaymentAuthorizationRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly Money $amount,
        public readonly string $idempotencyKey,
        public readonly ?string $instrumentToken = null,
        public readonly ?string $customerReference = null,
        public readonly ?string $description = null,
        public readonly ?string $statementDescriptor = null,
        public readonly ?string $returnUrl = null,
        public readonly array $metadata = [],
    ) {}
}
