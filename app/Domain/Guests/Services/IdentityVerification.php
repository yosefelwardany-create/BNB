<?php

declare(strict_types=1);

namespace App\Domain\Guests\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Guests\Models\Guest;
use App\Domain\Integrations\DataObjects\IdentityCheckRequest;
use App\Domain\Integrations\DataObjects\IdentityCheckResult;
use App\Domain\Integrations\Registries\IdentityVerifierRegistry;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Verifying a guest's identity, and recording what was actually established.
 *
 * `verification_status` used to be a column with a default that nothing ever
 * changed. It now moves only for a reason, and the reasons are kept.
 *
 * The statuses are deliberately five rather than two, because two forces a lie:
 *
 *  - `unverified` — nobody has looked.
 *  - `pending` — a check is under way.
 *  - `review` — the checks that could be made passed, and a person must decide.
 *    The local verifier can never produce anything better than this, and saying
 *    so is the point.
 *  - `verified` — established. Only a live provider or a named person may set
 *    this.
 *  - `rejected` — the document is not good.
 *
 * A guest is never marked verified by the simulated verifier. It cannot confirm
 * a document exists or that the holder is present, and labelling its output as
 * verification would be exactly the untruth this product refuses elsewhere.
 */
class IdentityVerification
{
    public const UNVERIFIED = 'unverified';

    public const PENDING = 'pending';

    public const REVIEW = 'review';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    public function __construct(
        private readonly IdentityVerifierRegistry $verifiers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Run a check against the configured verifier.
     *
     * @param  array<string, mixed>  $document
     */
    public function check(Guest $guest, array $document): array
    {
        $verifier = $this->verifiers->default();

        $type = (string) ($document['document_type'] ?? $guest->document_type ?? '');
        $number = (string) ($document['document_number'] ?? $guest->document_number ?? '');

        if ($type === '' || $number === '') {
            throw new HttpException(422, 'A document type and number are needed before a check can run.');
        }

        $expiry = isset($document['document_expiry'])
            ? CarbonImmutable::parse((string) $document['document_expiry'])
            : $guest->document_expiry;

        $result = $verifier->check(new IdentityCheckRequest(
            documentType: $type,
            documentNumber: $number,
            firstName: (string) $guest->first_name,
            lastName: (string) $guest->last_name,
            dateOfBirth: isset($document['date_of_birth'])
                ? CarbonImmutable::parse((string) $document['date_of_birth'])
                : $guest->date_of_birth,
            expiry: $expiry,
            countryCode: $document['country_code'] ?? $guest->country_code,
            reference: $guest->getKey(),
        ));

        return DB::transaction(function () use ($guest, $document, $type, $number, $expiry, $result, $verifier): array {
            // The document is stored whatever the outcome — it is what was
            // presented, and a rejection nobody can look at afterwards is a
            // rejection nobody can appeal.
            $guest->forceFill(array_filter([
                'document_type' => $type,
                'document_number' => $number,
                'document_expiry' => $expiry,
                'date_of_birth' => isset($document['date_of_birth'])
                    ? CarbonImmutable::parse((string) $document['date_of_birth'])
                    : $guest->date_of_birth,
            ], static fn ($value): bool => $value !== null));

            $status = $this->statusFor($result);

            // An unreachable provider tells us nothing about the guest, so it
            // must not move them backwards from wherever they already were.
            if ($result->isConclusive()) {
                $guest->verification_status = $status;
                $guest->verified_at = $status === self::VERIFIED ? now() : null;
            }

            $guest->save();

            $this->audit->record(
                action: 'guest.identity_checked',
                subject: $guest,
                newValues: [
                    'outcome' => $result->outcome,
                    'status' => $guest->verification_status,
                    'verifier' => $verifier->key(),
                    'is_simulated' => $result->isSimulated,
                ],
                description: sprintf(
                    'Identity check by %s: %s.%s',
                    $verifier->displayName(),
                    $result->outcome,
                    $result->isSimulated ? ' No real verification service was involved.' : '',
                ),
            );

            return [
                'guest' => $guest->fresh(),
                'result' => $result,
                // Carried to the caller so an interface can never present a
                // simulated check as a completed one.
                'is_simulated' => $result->isSimulated,
                'simulation_reason' => $verifier->isLive() ? null : $verifier->simulationReason(),
            ];
        });
    }

    /**
     * A person decides.
     *
     * The only path to `verified` while the configured verifier is a simulation,
     * and it records who decided — because "the system verified them" is not an
     * answer anybody can stand behind when it turns out to be wrong.
     */
    public function decide(Guest $guest, bool $approved, User $actor, string $reason): Guest
    {
        if (trim($reason) === '') {
            throw new HttpException(422, 'A reason is required when deciding an identity check.');
        }

        $previous = $guest->verification_status;

        $guest->forceFill([
            'verification_status' => $approved ? self::VERIFIED : self::REJECTED,
            'verified_at' => $approved ? now() : null,
        ])->save();

        $this->audit->record(
            action: $approved ? 'guest.identity_approved' : 'guest.identity_rejected',
            subject: $guest,
            oldValues: ['verification_status' => $previous],
            newValues: ['verification_status' => $guest->verification_status],
            description: sprintf('%s: %s', $actor->email, $reason),
        );

        return $guest->fresh();
    }

    /**
     * Whether a guest may be treated as verified right now.
     *
     * Expiry is re-checked at read time rather than trusted from the status: a
     * document that was valid when it was checked and has since expired is not
     * a verified guest, and nothing would otherwise notice the day it lapsed.
     */
    public function isCurrentlyVerified(Guest $guest): bool
    {
        if ($guest->verification_status !== self::VERIFIED) {
            return false;
        }

        return $guest->document_expiry === null || ! $guest->document_expiry->isPast();
    }

    private function statusFor(IdentityCheckResult $result): string
    {
        return match ($result->outcome) {
            // Only a live provider reaches VERIFIED through this path; the
            // simulated one returns MANUAL_REVIEW by design.
            IdentityCheckResult::VERIFIED => self::VERIFIED,
            IdentityCheckResult::REJECTED => self::REJECTED,
            IdentityCheckResult::MANUAL_REVIEW => self::REVIEW,
            default => self::PENDING,
        };
    }
}
