<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\AccountRemoval;
use App\Domain\Owners\Services\ClientAccounts;
use App\Domain\Platform\Services\PlatformAuditLogger;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\Platform\PlatformOrganizationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Creating client accounts, from the owner's workspace.
 *
 * This is the onboarding flow's first step. The owner creates the account and
 * its login here; connecting Hostex, pulling and mapping the client's
 * properties then happen through the ordinary workspace with the new account
 * selected, where every action is scoped to it. The client receives an
 * invitation (a password-reset link) and lands on the read-only portal.
 */
class PlatformClientController extends Controller
{
    public function __construct(
        private readonly ClientAccounts $clients,
        private readonly PlatformAuditLogger $audit,
        private readonly AccountRemoval $removal,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_name' => ['required', 'string', 'max:160'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'base_currency' => ['required', 'string', 'size:3', Rule::in(config('pms.currencies'))],
            'timezone' => ['required', 'string', 'timezone'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'send_invitation' => ['sometimes', 'boolean'],
        ]);

        $result = $this->clients->provision(
            organizationAttributes: array_filter([
                'name' => $data['organization_name'],
                'legal_name' => $data['legal_name'] ?? null,
                'base_currency' => $data['base_currency'],
                'timezone' => $data['timezone'],
                'country_code' => $data['country_code'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            holderAttributes: [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? null,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'timezone' => $data['timezone'],
            ],
            sendInvitation: (bool) ($data['send_invitation'] ?? true),
        );

        $this->audit->record(
            action: 'organization.created',
            actor: $this->currentUser(),
            organization: $result['organization'],
            subject: $result['organization'],
            description: sprintf('Client account created for %s (%s).', $result['organization']->name, $data['email']),
        );

        return response()->json([
            'message' => $result['invitation_sent']
                ? 'The client account was created and an invitation has been sent.'
                : 'The client account was created. No invitation was sent.',
            'data' => (new PlatformOrganizationResource(
                $result['organization']->loadCount(['memberships' => fn ($q) => $q->withoutGlobalScope('organization')]),
            ))->resolve(),
            'meta' => [
                'client' => $this->clients->describe($result['organization']),
                'invitation_sent' => $result['invitation_sent'],
            ],
        ], 201);
    }

    /**
     * Re-send the client's sign-in invitation.
     */
    public function invite(Organization $organization): JsonResponse
    {
        $state = $this->clients->describe($organization);
        $email = $state['account_holder']['email'] ?? null;

        abort_if($email === null, 422, 'This account has no account holder with an email address yet.');

        $status = Password::broker()->sendResetLink(['email' => $email]);

        $this->audit->record(
            action: 'organization.client_invited',
            actor: $this->currentUser(),
            organization: $organization,
            subject: $organization,
            description: sprintf('Sign-in invitation sent to %s.', $email),
            context: ['status' => $status],
        );

        return response()->json([
            'message' => $status === Password::RESET_LINK_SENT
                ? sprintf('An invitation has been sent to %s.', $email)
                : 'The invitation could not be sent; check that the client holds a login.',
            'meta' => ['status' => $status],
        ]);
    }

    /**
     * Make one login the account's only login, read-only.
     *
     * The chosen login becomes the client login and every other login in the
     * account is suspended. Requires a reason, recorded in both the platform's
     * trail and the account's own.
     */
    public function soleLogin(Request $request, Organization $organization, string $membership): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $result = $this->clients->makeSoleClientLogin($organization, $membership, $data['reason']);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $email = $result['membership']->user?->email;

        $this->audit->record(
            action: 'organization.sole_client_login',
            actor: $this->currentUser(),
            organization: $organization,
            subject: $organization,
            description: sprintf(
                '%s is now the only login for %s; %d other login(s) suspended: %s',
                $email,
                $organization->name,
                count($result['suspended']),
                $data['reason'],
            ),
            context: ['client_login' => $email, 'suspended' => $result['suspended']],
        );

        return response()->json([
            'message' => $result['suspended'] === []
                ? sprintf('%s is now the client login. It can only read.', $email)
                : sprintf(
                    '%s is now the only login for this account, and it can only read. Suspended: %s.',
                    $email,
                    implode(', ', $result['suspended']),
                ),
            'meta' => [
                'client' => $this->clients->describe($organization),
                'suspended' => $result['suspended'],
            ],
        ]);
    }

    /**
     * Delete a client account and everything it holds.
     *
     * Irreversible, so it asks for three things: the account must already be
     * suspended or cancelled (nobody is using it), the exact account name typed
     * back, and a reason. The platform's audit keeps the deletion on record.
     */
    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'confirm_name' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if (! in_array($organization->status, [OrganizationStatus::Suspended, OrganizationStatus::Cancelled], true)) {
            return response()->json([
                'message' => 'Suspend or cancel the account before deleting it, so nobody is using it when it goes.',
            ], 422);
        }

        if (trim($data['confirm_name']) !== $organization->name) {
            return response()->json([
                'message' => 'The name typed does not match the account name. Nothing was deleted.',
            ], 422);
        }

        $name = $organization->name;
        $id = (string) $organization->getKey();

        // The record and the deletion stand or fall together.
        $result = DB::transaction(function () use ($organization, $name, $id, $data): array {
            $this->audit->record(
                action: 'organization.deleted',
                actor: $this->currentUser(),
                organization: $organization,
                subject: $organization,
                description: sprintf('Client account %s deleted: %s', $name, $data['reason']),
                context: ['organization_id' => $id, 'reason' => $data['reason']],
            );

            return $this->removal->remove($organization);
        });

        return response()->json([
            'message' => sprintf(
                '%s was deleted with everything it held.%s',
                $name,
                $result['logins_removed'] === [] ? '' : ' Logins removed: '.implode(', ', $result['logins_removed']).'.',
            ),
            'meta' => $result,
        ]);
    }

    /**
     * Reset a login's password.
     *
     * Either a reset link to the login's email, or a new password generated
     * here and shown once, to pass on by a channel the platform owner trusts.
     * Both end every signed-in session of that login. A platform owner's
     * password is never reset from here: they change their own.
     */
    public function resetPassword(Request $request, Organization $organization, string $membership): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['link', 'generate'])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $seat = Membership::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->whereKey($membership)
            ->first();

        abort_if($seat === null, 404);

        $user = User::query()->find($seat->user_id);

        abort_if($user === null, 404);

        if ($user->isPlatformAdmin()) {
            return response()->json([
                'message' => 'A platform owner changes their own password from their profile.',
            ], 422);
        }

        $password = null;

        if ($data['method'] === 'generate') {
            $password = Str::password(16, symbols: false);

            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();
            $user->tokens()->delete();
        } else {
            $status = Password::broker()->sendResetLink(['email' => $user->email]);
        }

        $this->audit->record(
            action: $data['method'] === 'generate' ? 'user.password_set' : 'user.password_reset_sent',
            actor: $this->currentUser(),
            organization: $organization,
            subject: $user,
            description: sprintf(
                '%s for %s: %s',
                $data['method'] === 'generate' ? 'New password set' : 'Password reset link sent',
                $user->email,
                $data['reason'],
            ),
            context: array_filter(['reason' => $data['reason'], 'status' => $status ?? null]),
        );

        if ($password !== null) {
            return response()->json([
                'message' => sprintf('A new password was set for %s and every signed-in session ended. It is shown once.', $user->email),
                'meta' => ['email' => $user->email, 'password' => $password],
            ])->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'message' => ($status ?? null) === Password::RESET_LINK_SENT
                ? sprintf('A password reset link was sent to %s.', $user->email)
                : sprintf('The reset link could not be sent to %s. Try setting a new password instead.', $user->email),
            'meta' => ['email' => $user->email, 'status' => $status ?? null],
        ]);
    }
}
