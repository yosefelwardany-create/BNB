<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * What a verifier concluded.
 *
 * Four outcomes, not a boolean, and the distinction is the point: a system that
 * cannot say "we could not tell" will be made to say "verified" instead.
 *
 *  - `verified` — the document checks out.
 *  - `rejected` — it does not. Something is wrong with the document.
 *  - `manual_review` — the provider is not confident. A person must look. This
 *    is not the guest's failure and must not be presented as one.
 *  - `unavailable` — the provider could not be reached. Nothing was learned
 *    about the guest at all, and retrying is the right response.
 */
final class IdentityCheckResult
{
    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    public const MANUAL_REVIEW = 'manual_review';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public readonly string $outcome,
        public readonly array $reasons = [],
        public readonly ?string $externalReference = null,
        public readonly bool $isSimulated = false,
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function verified(
        ?string $reference = null,
        bool $simulated = false,
        array $metadata = [],
    ): self {
        return new self(self::VERIFIED, [], $reference, $simulated, $metadata);
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function rejected(array $reasons, ?string $reference = null, bool $simulated = false): self
    {
        return new self(self::REJECTED, $reasons, $reference, $simulated);
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function needsReview(array $reasons, ?string $reference = null, bool $simulated = false): self
    {
        return new self(self::MANUAL_REVIEW, $reasons, $reference, $simulated);
    }

    public static function unavailable(string $reason, bool $simulated = false): self
    {
        return new self(self::UNAVAILABLE, [$reason], null, $simulated);
    }

    public function succeeded(): bool
    {
        return $this->outcome === self::VERIFIED;
    }

    /**
     * Whether this told us anything about the guest at all.
     *
     * An unreachable provider did not: recording that as a rejection would blame
     * the guest for our outage.
     */
    public function isConclusive(): bool
    {
        return $this->outcome !== self::UNAVAILABLE;
    }
}
