<?php

declare(strict_types=1);

namespace App\Domain\Users\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Enrolling in two-factor authentication, and the challenge at sign-in.
 *
 * The part of MFA that actually protects anybody is the challenge, and the part
 * that is usually got wrong is what sits between the password and the code. So
 * that gap is designed rather than improvised:
 *
 *  - A correct password with MFA enabled produces **no session and no bearer
 *    token**, only a short-lived challenge reference. A half-issued token that
 *    "only works for the MFA endpoint" is a token, and tokens get used.
 *  - The challenge lives in the cache with a five-minute expiry, keyed by a
 *    random reference. Nothing about the user is recoverable from the reference.
 *  - Challenges are single-use and attempts are counted. Six digits is a million
 *    possibilities, which is a great many if you may only guess five times and
 *    nothing at all if you may guess forever.
 *
 * Secrets and recovery codes are encrypted at rest by the model's casts. A
 * recovery code is stored only as a hash, so this service can verify one and
 * cannot show anybody theirs.
 */
class MfaService
{
    /** How long somebody has to type a code before they must start again. */
    private const CHALLENGE_MINUTES = 5;

    /** Guesses allowed against one challenge. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly TotpService $totp,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Begin enrolment: a secret and the URI an authenticator app reads.
     *
     * The secret is written to the user immediately but `mfa_enabled` stays
     * false, so an abandoned enrolment leaves the account exactly as it was.
     * Nothing is enforced until a code proves the app actually holds the secret
     * — enabling first is how somebody locks themselves out with a mistyped QR.
     *
     * @return array{secret: string, uri: string}
     */
    public function beginEnrolment(User $user, string $issuer): array
    {
        if ($user->mfa_enabled) {
            throw new HttpException(422, 'Two-factor authentication is already switched on for this account.');
        }

        $secret = $this->totp->generateSecret();

        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => null,
            'mfa_recovery_codes' => null,
        ])->save();

        return [
            'secret' => $secret,
            'uri' => $this->totp->provisioningUri($secret, $user->email, $issuer),
        ];
    }

    /**
     * Finish enrolment once a code proves the app holds the secret.
     *
     * @return list<string> The recovery codes, in clear, for the only time.
     */
    public function confirmEnrolment(User $user, string $code): array
    {
        if ($user->mfa_enabled) {
            throw new HttpException(422, 'Two-factor authentication is already switched on.');
        }

        $secret = $user->mfa_secret;

        if (! is_string($secret) || $secret === '') {
            throw new HttpException(422, 'Start enrolment before confirming it.');
        }

        if (! $this->totp->verify($secret, $code)) {
            throw new HttpException(422, 'That code is not correct. Check your authenticator app and try the next one.');
        }

        $codes = $this->totp->generateRecoveryCodes();

        $user->forceFill([
            'mfa_enabled' => true,
            'mfa_confirmed_at' => now(),
            // Hashes only. A recovery code that can be read out of the database
            // is a password stored in clear.
            'mfa_recovery_codes' => array_map(
                static fn (string $plain): string => Hash::make($plain),
                $codes,
            ),
        ])->save();

        $this->audit->record(
            action: 'user.mfa_enabled',
            subject: $user,
            description: 'Two-factor authentication switched on.',
        );

        return $codes;
    }

    /**
     * Switch it off.
     *
     * Requires a current code or a recovery code as well as the password: a
     * stolen password must not be enough to remove the thing protecting against
     * a stolen password.
     */
    public function disable(User $user, string $code): void
    {
        if (! $user->mfa_enabled) {
            return;
        }

        if (! $this->verifyCode($user, $code)) {
            throw new HttpException(422, 'That code is not correct.');
        }

        $user->forceFill([
            'mfa_enabled' => false,
            'mfa_secret' => null,
            'mfa_recovery_codes' => null,
            'mfa_confirmed_at' => null,
        ])->save();

        $this->audit->record(
            action: 'user.mfa_disabled',
            subject: $user,
            description: 'Two-factor authentication switched off.',
        );
    }

    /**
     * Replace the recovery codes.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->totp->generateRecoveryCodes();

        $user->forceFill([
            'mfa_recovery_codes' => array_map(
                static fn (string $plain): string => Hash::make($plain),
                $codes,
            ),
        ])->save();

        $this->audit->record(
            action: 'user.mfa_recovery_codes_regenerated',
            subject: $user,
            description: 'Recovery codes replaced. The previous ones no longer work.',
        );

        return $codes;
    }

    /**
     * Whether a code is a valid TOTP or an unused recovery code.
     *
     * A recovery code is consumed on use, which is the whole point of calling it
     * one. Consumed here rather than by the caller, so no path can verify one and
     * forget to burn it.
     */
    public function verifyCode(User $user, string $code): bool
    {
        $secret = $user->mfa_secret;

        if (is_string($secret) && $secret !== '' && $this->totp->verify($secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    /**
     * Hand out a reference for a password that was correct but is not enough.
     *
     * Deliberately not a token of any kind. It identifies a pending sign-in and
     * authorises nothing.
     */
    public function issueChallenge(User $user): array
    {
        $reference = (string) Str::ulid();

        Cache::put(
            $this->challengeKey($reference),
            ['user_id' => $user->getKey(), 'attempts' => 0],
            now()->addMinutes(self::CHALLENGE_MINUTES),
        );

        return [
            'reference' => $reference,
            'expires_in' => self::CHALLENGE_MINUTES * 60,
        ];
    }

    /**
     * Redeem a challenge, returning the user it belongs to.
     *
     * Every failure path deletes nothing except on the last attempt, so a typo
     * does not force somebody back to the password screen — but the fifth wrong
     * guess ends the challenge rather than leaving it open to a sixth.
     */
    public function completeChallenge(string $reference, string $code): User
    {
        $key = $this->challengeKey($reference);
        $state = Cache::get($key);

        if (! is_array($state)) {
            throw new HttpException(422, 'That sign-in attempt has expired. Please start again.');
        }

        $user = User::query()->find($state['user_id'] ?? null);

        if (! $user instanceof User) {
            Cache::forget($key);

            throw new HttpException(422, 'That sign-in attempt is no longer valid.');
        }

        if ($this->verifyCode($user, $code)) {
            // Single use. Without this, an intercepted reference and a code that
            // is still inside its window could be replayed.
            Cache::forget($key);

            return $user;
        }

        $attempts = (int) ($state['attempts'] ?? 0) + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            Cache::forget($key);

            $this->audit->record(
                action: 'user.mfa_challenge_exhausted',
                subject: $user,
                description: sprintf('Five incorrect codes; the sign-in attempt was abandoned.'),
            );

            throw new HttpException(422, 'Too many incorrect codes. Please sign in again.');
        }

        Cache::put(
            $key,
            ['user_id' => $user->getKey(), 'attempts' => $attempts],
            now()->addMinutes(self::CHALLENGE_MINUTES),
        );

        throw new HttpException(422, sprintf(
            'That code is not correct. %d attempt(s) left.',
            self::MAX_ATTEMPTS - $attempts,
        ));
    }

    /**
     * Burn a recovery code if it matches an unused one.
     */
    private function consumeRecoveryCode(User $user, string $candidate): bool
    {
        $codes = $user->mfa_recovery_codes;

        if (! is_array($codes) || $codes === []) {
            return false;
        }

        $candidate = strtolower(trim($candidate));

        foreach ($codes as $index => $hash) {
            if (! is_string($hash) || ! Hash::check($candidate, $hash)) {
                continue;
            }

            unset($codes[$index]);

            $remaining = array_values($codes);

            $user->forceFill(['mfa_recovery_codes' => $remaining])->save();

            $this->audit->record(
                action: 'user.mfa_recovery_code_used',
                subject: $user,
                description: sprintf(
                    'A recovery code was used to sign in. %d remain.',
                    count($remaining),
                ),
            );

            return true;
        }

        return false;
    }

    private function challengeKey(string $reference): string
    {
        return 'mfa:challenge:'.$reference;
    }
}
