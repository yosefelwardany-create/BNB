<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

use Carbon\CarbonImmutable;

/**
 * What is being checked.
 *
 * Carries the document details and, optionally, an image. Deliberately not the
 * guest model: a verifier has no business with somebody's booking history, and
 * passing the whole record would make it possible to send it somewhere.
 */
final class IdentityCheckRequest
{
    public function __construct(
        public readonly string $documentType,
        public readonly string $documentNumber,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?CarbonImmutable $dateOfBirth = null,
        public readonly ?CarbonImmutable $expiry = null,
        public readonly ?string $countryCode = null,
        /** Raw image bytes, when the provider reads a scan. */
        public readonly ?string $documentImage = null,
        public readonly ?string $reference = null,
    ) {}
}
