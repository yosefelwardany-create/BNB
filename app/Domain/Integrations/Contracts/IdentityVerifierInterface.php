<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\DataObjects\IdentityCheckRequest;
use App\Domain\Integrations\DataObjects\IdentityCheckResult;

/**
 * Checking that a guest is who they say they are.
 *
 * Short-term rental operators are required to verify guests in a growing number
 * of jurisdictions, and every one of them expects a record of what was checked
 * and when. Until now `verification_status` was a column nothing ever wrote.
 *
 * The contract is shaped by one uncomfortable fact: identity checks fail, and a
 * system that cannot express "we could not tell" will be made to express
 * "verified" instead. So a result carries four outcomes rather than a boolean —
 * verified, rejected, needs a person to look, and provider unavailable — and the
 * last two are not failures of the guest.
 */
interface IdentityVerifierInterface
{
    public function key(): string;

    public function displayName(): string;

    /**
     * Whether this implementation reaches a real verification service.
     */
    public function isLive(): bool;

    /**
     * Why it does not, phrased for a person. Null when it does.
     */
    public function simulationReason(): ?string;

    /**
     * Check a document.
     */
    public function check(IdentityCheckRequest $request): IdentityCheckResult;

    /**
     * The document types this implementation can read.
     *
     * @return list<string>
     */
    public function supportedDocumentTypes(): array;
}
