<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * A time-bounded door code.
 *
 * The window is always explicit and absolute: the platform computes it from
 * the reservation in the *property's* timezone before it reaches the provider,
 * so a lock in another timezone cannot open early or late.
 */
final class AccessCodeRequest
{
    public function __construct(
        public readonly \DateTimeImmutable $validFrom,
        public readonly \DateTimeImmutable $validUntil,
        public readonly string $label,
        /** Null asks the provider to generate one. */
        public readonly ?string $code = null,
        public readonly ?string $reservationReference = null,
    ) {}
}
