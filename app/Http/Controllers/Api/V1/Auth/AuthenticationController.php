<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Users\Models\LoginHistory;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Users\Services\MfaService;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Session and token issuance.
 *
 * The admin SPA authenticates with a cookie session (Sanctum stateful); mobile
 * apps and machine integrations request a bearer token instead. Both paths run
 * through the same credential check, rate limiter and login history.
 */
class AuthenticationController extends Controller
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly MfaService $mfa,
    ) {}

    /**
     * Sign in. Returns a session cookie, and a bearer token when
     * `device_name` is supplied.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:120'],
            'organization' => ['sometimes', 'string', 'max:64'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $this->assertNotRateLimited($request, $credentials['email']);

        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($this->throttleKey($request, $credentials['email']));

            $this->recordLogin($request, $credentials['email'], $user, false, 'invalid_credentials');

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        if (! $user->status->canAuthenticate()) {
            $this->recordLogin($request, $credentials['email'], $user, false, 'account_'.$user->status->value);

            throw ValidationException::withMessages([
                'email' => __('This account is not active. Please contact your administrator.'),
            ]);
        }

        $memberships = Membership::query()
            ->withoutGlobalScope('organization')
            ->with('organization')
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->get();

        if ($memberships->isEmpty() && ! $user->isPlatformAdmin()) {
            $this->recordLogin($request, $credentials['email'], $user, false, 'no_membership');

            throw ValidationException::withMessages([
                'email' => __('This account does not belong to an active organization.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey($request, $credentials['email']));

        // A correct password is not a sign-in when a second factor is enabled.
        // No session and no token is issued here — only a reference to a pending
        // attempt, which authorises nothing. A half-issued token that "only
        // works for the MFA endpoint" is still a token, and tokens get used.
        if ($user->mfa_enabled) {
            $this->recordLogin($request, $credentials['email'], $user, false, 'mfa_required');

            return response()->json([
                'mfa_required' => true,
                'challenge' => $this->mfa->issueChallenge($user),
                'message' => 'Enter the code from your authenticator app.',
            ]);
        }

        return $this->completeSignIn($request, $user, $credentials);
    }

    /**
     * Issue the session or token, once every check has passed.
     *
     * Shared by the password-only path and the one that completes a two-factor
     * challenge, so the two cannot drift apart in what they return or in what
     * they record. Public for the MFA controller to call; it is not a route.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function completeSignIn(Request $request, User $user, array $credentials): JsonResponse
    {
        $memberships = $this->activeMembershipsFor($user);

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        $this->recordLogin($request, $user->email, $user, true);

        $payload = [
            'user' => (new UserResource($user))->toArray($request),
            'organizations' => $memberships->map(fn (Membership $m): array => [
                'id' => $m->organization_id,
                'name' => $m->organization?->name,
                'slug' => $m->organization?->slug,
                'status' => $m->organization?->status->value,
                'base_currency' => $m->organization?->base_currency,
                'timezone' => $m->organization?->timezone,
                'default_portal' => $m->default_portal,
            ])->values()->all(),
        ];

        // Bearer token flow (mobile and machine clients).
        if (! empty($credentials['device_name'])) {
            $organizationId = $this->tokenOrganizationId($credentials['organization'] ?? null, $memberships);

            $abilities = ['*'];

            if ($organizationId !== null) {
                // Binding the token to one organization means a stolen token
                // cannot be replayed against another tenant.
                $abilities = ['*', 'organization:'.$organizationId];
            }

            $token = $user->createToken($credentials['device_name'], $abilities, now()->addDays(90));

            $payload['token'] = $token->plainTextToken;
            $payload['token_expires_at'] = $token->accessToken->expires_at?->toIso8601String();
        } else {
            Auth::guard('web')->login($user, (bool) ($credentials['remember'] ?? false));

            // Only where there is a session to regenerate. A JSON client that
            // signs in without `device_name` and without a session cookie —
            // a script, a health check, anything not the SPA — has no session
            // store bound, and calling session() on it threw a 500 rather than
            // returning the perfectly good payload above.
            if ($request->hasSession()) {
                $request->session()->regenerate();
            }
        }

        return response()->json($payload);
    }

    /**
     * @return Collection<int, Membership>
     */
    private function activeMembershipsFor(User $user)
    {
        return Membership::query()
            ->withoutGlobalScope('organization')
            ->with('organization')
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->get();
    }

    /**
     * Sign out: destroys the session, and revokes the presented bearer token.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * The authenticated user, their membership and their effective permissions
     * in the current organization. The SPA calls this on boot to build its
     * navigation and hide actions the user cannot perform.
     */
    /**
     * The signed-in session.
     *
     * Deliberately tolerant of having no organization. A platform
     * administrator holds no membership anywhere — governing the platform does
     * not require a seat in a customer's company, and giving them one would
     * misrepresent how the console's authorisation works — so requiring a
     * tenant here made the console unreachable: the token was issued, this call
     * refused it, and the client returned the operator to the sign-in screen.
     *
     * Every *tenant* route still resolves an organization or refuses. This one
     * describes the session, not a tenant's data.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $organization = $this->organizationOrNull();

        $membership = $organization === null
            ? null
            : $this->access->membership($user, $organization);

        return response()->json([
            'user' => (new UserResource($user))->toArray($request),
            'organization' => $organization === null ? null : [
                'id' => $organization->getKey(),
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status->value,
                'base_currency' => $organization->base_currency,
                'timezone' => $organization->timezone,
                'locale' => $organization->locale,
                'branding' => $organization->branding,
            ],
            'membership' => $membership === null ? null : [
                'id' => $membership->getKey(),
                'job_title' => $membership->job_title,
                'default_portal' => $membership->default_portal,
                'restricted_to_properties' => $membership->restricted_to_properties,
                'roles' => $membership->roles->map(fn ($role): array => [
                    'id' => $role->getKey(),
                    'slug' => $role->slug,
                    'name' => $role->name,
                ])->values()->all(),
            ],
            'permissions' => $user->isPlatformAdmin()
                ? ['*']
                : ($organization === null ? [] : $this->access->permissionsFor($user, $organization)),
            'is_platform_admin' => $user->isPlatformAdmin(),
            'restricted_property_ids' => $organization === null
                ? null
                : $this->access->restrictedPropertyIds($user, $organization),
        ]);
    }

    private function tokenOrganizationId(?string $requested, $memberships): ?string
    {
        if ($requested !== null) {
            $match = $memberships->first(
                fn (Membership $m): bool => $m->organization_id === $requested
                    || $m->organization?->slug === $requested
            );

            if ($match === null) {
                throw ValidationException::withMessages([
                    'organization' => __('You do not belong to that organization.'),
                ]);
            }

            return $match->organization_id;
        }

        return $memberships->count() === 1 ? $memberships->first()->organization_id : null;
    }

    private function assertNotRateLimited(Request $request, string $email): void
    {
        $key = $this->throttleKey($request, $email);

        if (! RateLimiter::tooManyAttempts($key, 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        $this->recordLogin($request, $email, null, false, 'rate_limited');

        throw ValidationException::withMessages([
            'email' => __('Too many sign-in attempts. Please try again in :seconds seconds.', [
                'seconds' => $seconds,
            ]),
        ]);
    }

    private function throttleKey(Request $request, string $email): string
    {
        return 'login:'.mb_strtolower($email).'|'.$request->ip();
    }

    private function recordLogin(
        Request $request,
        string $email,
        ?User $user,
        bool $successful,
        ?string $failureReason = null,
    ): void {
        LoginHistory::query()->create([
            'user_id' => $user?->getKey(),
            'email' => $email,
            'successful' => $successful,
            'failure_reason' => $failureReason,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);
    }
}
