<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Team;

use App\Domain\Users\Models\Invitation;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Services\InvitationService;
use App\Domain\Users\Services\TeamAccess;
use App\Http\Controllers\Controller;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The account's team: who is in it, and what each of them sees.
 *
 * The rules on who may give what live in {@see TeamAccess}; the routes say
 * which of the team permissions each action needs.
 */
class TeamController extends Controller
{
    public function __construct(private readonly TeamAccess $team) {}

    public function index(Request $request): JsonResponse
    {
        $members = Membership::query()
            ->with(['user', 'roles.permissions', 'permissionOverrides', 'properties'])
            ->get()
            ->sortBy(fn (Membership $membership): string => mb_strtolower($membership->user?->fullName() ?? ''))
            ->values();

        $invitations = Invitation::query()->pending()->with('roles')->latest()->get();

        return response()->json(['data' => [
            'members' => $members->map(fn (Membership $membership): array => $this->team->describe($membership, $request->user()))->all(),
            'invitations' => $invitations->map(fn (Invitation $invitation): array => $this->team->describeInvitation($invitation))->all(),
        ]]);
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => $this->team->options($this->organization())]);
    }

    public function update(Request $request, Membership $membership): JsonResponse
    {
        $data = $request->validate($this->accessRules() + [
            'role_ids' => ['sometimes', 'array', 'min:1'],
            'role_ids.*' => ['string'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $changed = $this->team->change($membership, $data, $request->user());

        return response()->json([
            'message' => 'Access updated.',
            'data' => $this->team->describe($changed, $request->user()),
        ]);
    }

    public function deactivate(Request $request, Membership $membership): JsonResponse
    {
        $changed = $this->team->setActive($membership, false, $request->user());

        return response()->json([
            'message' => 'Switched off. They can no longer sign in to this account.',
            'data' => $this->team->describe($changed, $request->user()),
        ]);
    }

    public function reactivate(Request $request, Membership $membership): JsonResponse
    {
        $changed = $this->team->setActive($membership, true, $request->user());

        return response()->json([
            'message' => 'Switched back on.',
            'data' => $this->team->describe($changed, $request->user()),
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        $data = $request->validate($this->accessRules() + [
            'email' => ['required', 'email', 'max:190'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['string'],
        ]);

        $invitation = $this->team->invite($this->organization(), $data, $request->user());

        return response()->json([
            'message' => sprintf('Invitation sent to %s.', $invitation->email),
            'data' => $this->team->describeInvitation($invitation),
            // Shown once, to whoever invited them: the email may not reach the
            // person, or may not be sent at all, and the link is the only way in.
            'meta' => [
                'accept_url' => $invitation->plainToken === null ? null
                    : FrontendUrl::to('invitations/'.$invitation->plainToken),
            ],
        ], 201);
    }

    public function withdraw(Invitation $invitation, InvitationService $invitations): JsonResponse
    {
        $invitations->revoke($invitation);

        return response()->json(['message' => 'Invitation withdrawn.']);
    }

    /**
     * @return array<string, list<string>>
     */
    private function accessRules(): array
    {
        return [
            'allow' => ['sometimes', 'array'],
            'allow.*' => ['string', 'max:80'],
            'deny' => ['sometimes', 'array'],
            'deny.*' => ['string', 'max:80'],
            'restricted_to_properties' => ['sometimes', 'boolean'],
            'property_ids' => ['sometimes', 'array'],
            'property_ids.*' => ['string', 'max:40'],
        ];
    }
}
