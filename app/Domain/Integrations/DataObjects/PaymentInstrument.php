<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * A payment method to store against a customer for later charges.
 *
 * Only a provider-issued token ever reaches this object. Raw card details
 * never enter the platform.
 */
final class PaymentInstrument
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $customerReference,
        /** Single-use token produced by the provider's client-side SDK. */
        public readonly string $setupToken,
        public readonly ?string $holderName = null,
        public readonly array $metadata = [],
    ) {}
}
