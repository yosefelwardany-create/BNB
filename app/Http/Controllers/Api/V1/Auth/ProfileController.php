<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Your own name, email address and password.
 *
 * Deliberately outside the tenant middleware: this is about a person, not
 * about a company's data, and a platform administrator holds no membership
 * anywhere. Requiring a tenant here would leave the one account that governs
 * the platform unable to change its own password.
 *
 * Two rules do the security work, and both exist for the same reason — a
 * borrowed laptop with a live session is the realistic threat, not a stolen
 * password:
 *
 *  - **The current password is required to change the email or the password.**
 *    Without it, anyone who walks up to an unlocked screen can point the
 *    account at their own address and take it over at leisure.
 *  - **Changing the password ends every other session.** A password is changed
 *    because it may be known; leaving the sessions it protected alive makes the
 *    change ceremonial.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Update your own name, contact details, and email address.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'locale' => ['sometimes', 'string', 'max:10'],
            'email' => ['sometimes', 'email', 'max:255'],
            // Checked below rather than with `required_with`, so the failure
            // names the password rather than the email.
            'current_password' => ['sometimes', 'string'],
        ]);

        $changingEmail = isset($data['email'])
            && strtolower($data['email']) !== strtolower((string) $user->email);

        if ($changingEmail) {
            $this->assertCurrentPassword($request, $user->password);

            // Uniqueness is checked here rather than by a `unique` rule so the
            // message is the same whether the address is taken or not — a rule
            // that says "already taken" turns this endpoint into a way to
            // discover who holds an account.
            $taken = $user->newQuery()
                ->whereRaw('lower(email) = ?', [strtolower($data['email'])])
                ->whereKeyNot($user->getKey())
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'email' => __('That email address cannot be used.'),
                ]);
            }
        }

        $previousEmail = $user->email;

        $user->fill(collect($data)->except('current_password', 'email')->all());

        if ($changingEmail) {
            $user->email = $data['email'];

            // A new address has not been proven to belong to them. Verification
            // is not a gate on signing in here, so this records the truth
            // rather than locking anybody out.
            $user->email_verified_at = null;
        }

        $user->save();

        if ($changingEmail) {
            $this->audit->record(
                action: 'user.email_changed',
                subject: $user,
                oldValues: ['email' => $previousEmail],
                newValues: ['email' => $user->email],
                description: sprintf('Email changed from %s to %s.', $previousEmail, $user->email),
            );
        }

        return response()->json(['data' => (new UserResource($user->fresh()))->toArray($request)]);
    }

    /**
     * Change your own password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->currentUser();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            // The same rules registration applies. A password somebody chooses
            // from a settings screen protects exactly as much as the one they
            // chose at sign-up, so holding a lower bar here would be a hole in
            // the shape of convenience.
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()->uncompromised()],
        ]);

        $this->assertCurrentPassword($request, $user->password);

        if (Hash::check($data['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('Choose a password you have not used here before.'),
            ]);
        }

        $user->forceFill(['password' => $data['password']])->save();

        // Every other session ends. A password is changed because it might be
        // known, and leaving the sessions it protected alive would make the
        // change ceremonial. The token in hand survives, so the person doing
        // this is not signed out of the screen they are looking at.
        $current = $request->user()?->currentAccessToken();

        $user->tokens()
            ->when($current !== null, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->delete();

        $this->audit->record(
            action: 'user.password_changed',
            subject: $user,
            description: 'Password changed. Other sessions were ended.',
        );

        return response()->json([
            'data' => (new UserResource($user->fresh()))->toArray($request),
            'meta' => [
                'other_sessions_ended' => true,
                'message' => 'Your password was changed and every other session was signed out.',
            ],
        ]);
    }

    /**
     * Refuse unless the caller proves they know the current password.
     */
    private function assertCurrentPassword(Request $request, ?string $hashed): void
    {
        $supplied = (string) $request->input('current_password', '');

        if ($supplied === '' || ! Hash::check($supplied, (string) $hashed)) {
            throw ValidationException::withMessages([
                'current_password' => __('That is not your current password.'),
            ]);
        }
    }
}
