<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

use App\Support\Money\Money;
use DateTimeImmutable;

/**
 * Create a hosted payment page, used when the platform should never see card
 * details (direct bookings, balance-due requests emailed to a guest).
 */
final class PaymentLinkRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly Money $amount,
        public readonly string $idempotencyKey,
        public readonly string $description,
        public readonly ?string $customerEmail = null,
        public readonly ?string $returnUrl = null,
        public readonly ?DateTimeImmutable $expiresAt = null,
        public readonly array $metadata = [],
    ) {}
}
