<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Organization\Models\Organization;
use App\Domain\Owners\Services\ClientAccounts;
use App\Domain\Platform\Services\PlatformAuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\Platform\PlatformOrganizationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
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
}
