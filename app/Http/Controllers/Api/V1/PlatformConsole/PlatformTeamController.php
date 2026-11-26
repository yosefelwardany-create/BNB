<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\Invitation;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Services\InvitationService;
use App\Http\Controllers\Api\V1\Team\TeamController;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A client account's logins, from the client's page in Accounts.
 *
 * The platform adds logins to a client and decides what each can see and
 * change. It is the account's own team screen, run inside the client chosen
 * here rather than the one the session happens to be in, so the same rules
 * hold: a login belongs to this client alone, and the changes are checked and
 * recorded exactly as an account's own admin's would be.
 */
class PlatformTeamController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly TeamController $team,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        return $this->inside($organization, fn () => $this->team->index($request));
    }

    public function options(Organization $organization): JsonResponse
    {
        return $this->inside($organization, fn () => $this->team->options());
    }

    public function invite(Request $request, Organization $organization): JsonResponse
    {
        return $this->inside($organization, fn () => $this->team->invite($request));
    }

    public function withdraw(Organization $organization, string $invitation, InvitationService $invitations): JsonResponse
    {
        return $this->inside($organization, fn () => $this->team->withdraw(
            Invitation::query()->where('organization_id', $organization->getKey())->findOrFail($invitation),
            $invitations,
        ));
    }

    public function update(Request $request, Organization $organization, string $membership): JsonResponse
    {
        return $this->inside($organization, fn () => $this->team->update($request, $this->membership($organization, $membership)));
    }

    public function deactivate(Request $request, Organization $organization, string $membership): JsonResponse
    {
        return $this->inside($organization, fn () => $this->team->deactivate($request, $this->membership($organization, $membership)));
    }

    public function reactivate(Request $request, Organization $organization, string $membership): JsonResponse
    {
        return $this->inside($organization, fn () => $this->team->reactivate($request, $this->membership($organization, $membership)));
    }

    /**
     * @param  Closure(): JsonResponse  $action
     */
    private function inside(Organization $organization, Closure $action): JsonResponse
    {
        return $this->tenancy->runAs($organization, $action);
    }

    /**
     * One of this client's logins, and only this client's.
     */
    private function membership(Organization $organization, string $id): Membership
    {
        return Membership::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->findOrFail($id);
    }
}
