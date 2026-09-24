<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Users\Services\MfaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Enrolling in and removing two-factor authentication.
 *
 * Every write here re-checks the password, including the ones that already
 * require a code. Somebody who left a laptop unlocked must not be able to turn
 * off, or silently re-enrol, the thing protecting the account — and a session
 * that is minutes old is not evidence that the person at the keyboard is the
 * account holder.
 *
 * The challenge endpoint is the exception: it is reached *before* a session
 * exists, and its own credential is the short-lived reference the login handed
 * out.
 */
class MfaController extends Controller
{
    public function __construct(private readonly MfaService $mfa) {}

    /**
     * Where this account stands.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        return response()->json([
            'data' => [
                'enabled' => (bool) $user->mfa_enabled,
                'confirmed_at' => $user->mfa_confirmed_at?->toIso8601String(),
                // A count, never the codes. They exist as hashes and cannot be
                // shown again — which is why the count matters: somebody with
                // one left needs to know before they need it.
                'recovery_codes_remaining' => is_array($user->mfa_recovery_codes)
                    ? count($user->mfa_recovery_codes)
                    : 0,
            ],
        ]);
    }

    /**
     * Start enrolment. Returns the secret and a QR-ready URI.
     */
    public function begin(Request $request): JsonResponse
    {
        $this->confirmPassword($request);

        $result = $this->mfa->beginEnrolment(
            $this->currentUser(),
            (string) config('app.name', 'Habitat'),
        );

        return response()->json([
            'data' => $result,
            'meta' => [
                'notice' => 'Scan this with an authenticator app, then confirm with a code. '
                    .'Nothing changes on your account until you do.',
            ],
        ]);
    }

    /**
     * Finish enrolment with a code from the app.
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        $codes = $this->mfa->confirmEnrolment($this->currentUser(), $data['code']);

        return response()->json([
            'message' => 'Two-factor authentication is on.',
            'data' => ['recovery_codes' => $codes],
            'meta' => [
                'notice' => 'Store these now. Each works once, and they are the only way in '
                    .'if you lose your phone. Only hashes are kept, so they cannot be shown again.',
            ],
        ]);
    }

    /**
     * Switch it off. Needs the password *and* a current code.
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->confirmPassword($request);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $this->mfa->disable($this->currentUser(), $data['code']);

        return response()->json(['message' => 'Two-factor authentication is off.']);
    }

    /**
     * Replace the recovery codes, invalidating the old ones.
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $this->confirmPassword($request);

        $codes = $this->mfa->regenerateRecoveryCodes($this->currentUser());

        return response()->json([
            'message' => 'New recovery codes issued. The previous ones no longer work.',
            'data' => ['recovery_codes' => $codes],
        ]);
    }

    /**
     * The password is right; check the second factor.
     *
     * Reached without a session — the reference from the login is the credential.
     * Throttled per reference as well as per address: the reference is what an
     * attacker would iterate codes against.
     */
    public function challenge(Request $request, AuthenticationController $auth): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:32'],
            'device_name' => ['sometimes', 'string', 'max:120'],
            'organization' => ['sometimes', 'string', 'max:64'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $user = $this->mfa->completeChallenge($data['challenge'], $data['code']);

        // The session or token is issued by the same code path a password-only
        // sign-in uses, so the two cannot drift apart in what they return or in
        // what they record.
        return $auth->completeSignIn($request, $user, $data);
    }

    /**
     * Re-check the password on the current session.
     */
    private function confirmPassword(Request $request): void
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($data['password'], $this->currentUser()->password)) {
            throw ValidationException::withMessages([
                'password' => __('That password is not correct.'),
            ]);
        }
    }
}
