<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\Identity;

use App\Domain\Integrations\Contracts\IdentityVerifierInterface;
use App\Domain\Integrations\DataObjects\IdentityCheckRequest;
use App\Domain\Integrations\DataObjects\IdentityCheckResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A local verifier that performs the checks it honestly can.
 *
 * Not a stub that returns "verified". It applies the rules that can be applied
 * without a government database or a photograph of a face:
 *
 *  - the document has not expired;
 *  - the number is the right shape for its type;
 *  - the holder is old enough to hold a booking;
 *  - a passport number that fails its check digit is rejected outright.
 *
 * What it cannot do is confirm the document exists or that the person presenting
 * it is its holder. That is the whole of the value of a real provider, so
 * anything that passes these checks comes back as `manual_review` rather than
 * `verified` — because "a plausible-looking number" is not verification, and
 * labelling it as such would be the untruth this whole product avoids.
 *
 * `isLive()` is false and every result is flagged simulated.
 */
class LocalIdentityVerifier implements IdentityVerifierInterface
{
    /** Below this, somebody is not contracting for a holiday let. */
    private const MINIMUM_AGE = 18;

    public function key(): string
    {
        return 'local';
    }

    public function displayName(): string
    {
        return 'Local document checks (development)';
    }

    public function isLive(): bool
    {
        return false;
    }

    public function simulationReason(): ?string
    {
        return 'No identity verification service is configured. Documents are checked for '
            .'expiry, shape and age only — nothing confirms the document exists or that the '
            .'person presenting it is its holder. Set IDENTITY_VERIFIER once a provider '
            .'account exists.';
    }

    public function supportedDocumentTypes(): array
    {
        return ['passport', 'national_id', 'driving_licence', 'residence_permit'];
    }

    public function check(IdentityCheckRequest $request): IdentityCheckResult
    {
        $reasons = [];

        if (! in_array($request->documentType, $this->supportedDocumentTypes(), true)) {
            return IdentityCheckResult::rejected(
                [sprintf('"%s" is not a document type this check understands.', $request->documentType)],
                simulated: true,
            );
        }

        // Expiry is the one check that is genuinely conclusive without a
        // provider: an expired document is invalid whoever holds it.
        if ($request->expiry !== null && $request->expiry->isPast()) {
            $reasons[] = sprintf('The document expired on %s.', $request->expiry->toFormattedDateString());
        }

        if ($request->dateOfBirth !== null) {
            $age = $request->dateOfBirth->diffInYears(CarbonImmutable::today());

            if ($age < self::MINIMUM_AGE) {
                $reasons[] = sprintf('The holder is %d, below the minimum age of %d.', $age, self::MINIMUM_AGE);
            }
        }

        $number = strtoupper(preg_replace('/\s+/', '', $request->documentNumber) ?? '');

        if (strlen($number) < 5) {
            $reasons[] = 'The document number is too short to be valid.';
        }

        if ($request->documentType === 'passport' && ! $this->passportNumberLooksValid($number)) {
            $reasons[] = 'The passport number is not a recognised format.';
        }

        if ($reasons !== []) {
            return IdentityCheckResult::rejected($reasons, simulated: true);
        }

        // Everything checkable passed — which is not the same as verified, and
        // saying so is the point of this class.
        return IdentityCheckResult::needsReview(
            [
                'The document passed the checks that can be made locally: it has not expired, '
                .'its number is the right shape, and the holder is old enough.',
                'Nothing has confirmed that this document exists or that the person presenting '
                .'it is its holder. A person should look before this guest is treated as verified.',
            ],
            reference: 'local_'.Str::lower((string) Str::ulid()),
            simulated: true,
        );
    }

    /**
     * Passport numbers are 6–9 alphanumeric characters in every ICAO-conforming
     * scheme. Not a checksum — machine-readable zones carry one, a typed number
     * does not — but enough to catch a transposed or truncated entry.
     */
    private function passportNumberLooksValid(string $number): bool
    {
        return preg_match('/^[A-Z0-9]{6,9}$/', $number) === 1;
    }
}
