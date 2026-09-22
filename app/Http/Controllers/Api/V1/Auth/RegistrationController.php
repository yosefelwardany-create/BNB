<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Organization\Services\OrganizationProvisioner;
use App\Domain\Users\Models\Invitation;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\InvitationService;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Sign-up: creating a brand new organization, and accepting an invitation to
 * join an existing one.
 */
class RegistrationController extends Controller
{
    public function __construct(
        private readonly OrganizationProvisioner $provisioner,
        private readonly InvitationService $invitations,
    ) {}

    /**
     * Create a new organization and its first administrator.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_name' => ['required', 'string', 'max:160'],
            'base_currency' => ['sometimes', 'string', 'size:3', Rule::in(config('pms.currencies'))],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'country_code' => ['sometimes', 'string', 'size:2'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()->uncompromised()],
        ]);

        // A user may legitimately already exist (they work for another
        // company). They keep their credentials and simply gain a new seat —
        // but only if the supplied password actually matches, otherwise this
        // endpoint would be a way to discover registered email addresses.
        $existing = User::query()->where('email', $data['email'])->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'email' => __('An account with this email already exists. Please sign in instead.'),
            ]);
        }

        $result = $this->provisioner->provision(
            organizationAttributes: [
                'name' => $data['organization_name'],
                'base_currency' => $data['base_currency'] ?? 'USD',
                'timezone' => $data['timezone'] ?? 'UTC',
                'country_code' => $data['country_code'] ?? null,
                'contact_email' => $data['email'],
            ],
            adminAttributes: [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'email' => $data['email'],
                'password' => $data['password'],
                'timezone' => $data['timezone'] ?? 'UTC',
            ],
        );

        $result['user']->sendEmailVerificationNotification();

        return response()->json([
            'user' => (new UserResource($result['user']))->toArray($request),
            'organization' => [
                'id' => $result['organization']->getKey(),
                'name' => $result['organization']->name,
                'slug' => $result['organization']->slug,
                'base_currency' => $result['organization']->base_currency,
                'timezone' => $result['organization']->timezone,
            ],
        ], 201);
    }

    /**
     * Describe a pending invitation so the acceptance screen can show who is
     * inviting whom, without leaking anything beyond that.
     */
    public function showInvitation(string $token): JsonResponse
    {
        $invitation = $this->invitations->findPendingByToken($token);

        if ($invitation === null) {
            return response()->json(['message' => 'This invitation is no longer valid.'], 404);
        }

        return response()->json([
            'email' => $invitation->email,
            'first_name' => $invitation->first_name,
            'last_name' => $invitation->last_name,
            'organization_name' => $invitation->organization->name,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'requires_password' => ! User::query()->where('email', $invitation->email)->exists(),
        ]);
    }

    /**
     * Accept an invitation, creating the user account if they are new.
     */
    public function acceptInvitation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'password' => ['sometimes', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);

        $invitation = $this->invitations->findPendingByToken($data['token']);

        if ($invitation === null) {
            throw ValidationException::withMessages([
                'token' => __('This invitation is no longer valid.'),
            ]);
        }

        $membership = DB::transaction(
            fn () => $this->invitations->accept($invitation, $data)
        );

        return response()->json([
            'message' => 'Invitation accepted.',
            'organization' => [
                'id' => $invitation->organization_id,
                'name' => $invitation->organization->name,
                'slug' => $invitation->organization->slug,
            ],
            'user' => (new UserResource($membership->user))->toArray($request),
        ]);
    }
}
