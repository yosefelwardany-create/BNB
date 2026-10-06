<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Organization\Models\Organization;
use App\Domain\Owners\Services\ClientAccounts;
use App\Domain\Platform\Services\PlatformMetrics;
use App\Domain\Platform\Services\TenantAdministration;
use App\Http\Controllers\Controller;
use App\Http\Resources\Platform\PlatformOrganizationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The tenants on the platform.
 *
 * Every query here explicitly drops the organization scope. That is not
 * boilerplate: `Organization` itself is not tenant-scoped, but its relations are,
 * and a console that resolved a tenant would filter counts to whichever one the
 * operator last visited. The middleware clears the tenant for the same reason.
 *
 * Every mutating action requires a reason and goes through
 * {@see TenantAdministration}, which records it in the *customer's* audit trail.
 * These are acts of power over somebody else's business and the customer is
 * entitled to the record.
 */
class PlatformTenantController extends Controller
{
    public function __construct(
        private readonly TenantAdministration $tenants,
        private readonly PlatformMetrics $metrics,
        private readonly ClientAccounts $clients,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        // The membership count is a tenant-scoped relation read with no tenant
        // bound, which the scope now refuses unless told explicitly that
        // reading across accounts is the intent. It is.
        $query = Organization::query()
            ->withCount(['memberships' => fn ($q) => $q->withoutGlobalScope('organization')]);

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';

            $query->where(fn ($q) => $q
                ->where('name', 'ilike', $term)
                ->orWhere('slug', 'ilike', $term)
                ->orWhere('legal_name', 'ilike', $term)
                ->orWhere('contact_email', 'ilike', $term));
        }

        if ($request->filled('status')) {
            $query->whereIn('status', (array) $request->input('status'));
        }

        $sort = match ($request->string('sort')->toString()) {
            'name' => ['name', 'asc'],
            default => ['created_at', 'desc'],
        };

        return PlatformOrganizationResource::collection(
            $query->orderBy($sort[0], $sort[1])->paginate($this->perPage()),
        );
    }

    public function show(Organization $organization): JsonResponse
    {
        return response()->json([
            'data' => (new PlatformOrganizationResource($organization))->resolve(),
            // Counts and activity, plus the state of the client's account
            // holder, agreement and ownership rows so the owner can see at a
            // glance whether onboarding is complete.
            'meta' => $this->metrics->forOrganization($organization) + [
                'client' => $this->clients->describe($organization),
            ],
        ]);
    }

    /**
     * The platform's private notes on a tenant.
     *
     * Deliberately the only field this endpoint writes. A tenant's own name,
     * currency and timezone belong to the tenant, and a console that could edit
     * them would let an operator change a customer's data with no record on the
     * customer's side of why it changed.
     */
    public function update(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'platform_notes' => ['present', 'nullable', 'string', 'max:20000'],
        ]);

        $organization->forceFill(['platform_notes' => $data['platform_notes']])->save();

        return response()->json([
            'data' => (new PlatformOrganizationResource($organization->fresh()))->resolve(),
        ]);
    }

    public function suspend(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $updated = $this->tenants->suspend($organization, $data['reason'], $this->currentUser());

        return response()->json([
            'message' => 'The organization has been suspended and its sessions ended. No data was deleted.',
            'data' => (new PlatformOrganizationResource($updated))->resolve(),
        ]);
    }

    public function reinstate(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $updated = $this->tenants->reinstate($organization, $data['reason'], $this->currentUser());

        return response()->json([
            'message' => sprintf('The organization is %s again.', $updated->status->value),
            'data' => (new PlatformOrganizationResource($updated))->resolve(),
        ]);
    }

    public function cancel(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $updated = $this->tenants->cancel($organization, $data['reason'], $this->currentUser());

        return response()->json([
            'message' => 'The organization has been cancelled. Every record it holds is intact.',
            'data' => (new PlatformOrganizationResource($updated))->resolve(),
        ]);
    }

    /**
     * Who works at this tenant.
     *
     * Names, roles and last sign-in — enough to answer "who should I be talking
     * to" and to choose whom a support session should be viewed as. No tenant
     * content.
     */
    public function users(Organization $organization): JsonResponse
    {
        $memberships = $organization->memberships()
            ->withoutGlobalScope('organization')
            ->with(['user', 'roles'])
            ->get();

        return response()->json([
            'data' => $memberships->map(fn ($membership): array => [
                'membership_id' => $membership->id,
                'user_id' => $membership->user_id,
                'name' => $membership->user?->fullName(),
                'email' => $membership->user?->email,
                'status' => $membership->status,
                'job_title' => $membership->job_title,
                'roles' => $membership->roles->pluck('name')->all(),
                'is_platform_admin' => (bool) $membership->user?->is_platform_admin,
                'last_login_at' => $membership->user?->last_login_at?->toIso8601String(),
            ])->values(),
        ]);
    }
}
