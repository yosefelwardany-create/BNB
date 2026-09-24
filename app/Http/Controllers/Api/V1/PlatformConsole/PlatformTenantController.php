<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Services\PlatformMetrics;
use App\Domain\Platform\Services\TenantAdministration;
use App\Domain\Platform\Support\PlanFeature;
use App\Http\Controllers\Controller;
use App\Http\Resources\Platform\PlatformOrganizationResource;
use Carbon\CarbonImmutable;
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
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Organization::query()
            ->with('plan')
            ->withCount('memberships');

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

        if ($request->filled('plan_id')) {
            $query->where('plan_id', $request->string('plan_id')->toString());
        }

        // The operational queue: trials that have run out and nobody has acted
        // on. The most useful filter on this screen, because it is a list of
        // conversations somebody owes a customer.
        if ($request->boolean('expired_trials')) {
            $query->where('status', 'trial')
                ->whereNotNull('trial_ends_at')
                ->where('trial_ends_at', '<', now());
        }

        $sort = match ($request->string('sort')->toString()) {
            'name' => ['name', 'asc'],
            'trial_ends_at' => ['trial_ends_at', 'asc'],
            default => ['created_at', 'desc'],
        };

        return PlatformOrganizationResource::collection(
            $query->orderBy($sort[0], $sort[1])->paginate($this->perPage()),
        );
    }

    public function show(Organization $organization): JsonResponse
    {
        return response()->json([
            'data' => (new PlatformOrganizationResource($organization->load('plan')))->resolve(),
            // Usage, features and activity, from the same service the tenant's
            // own settings screen reads, so a support conversation cannot become
            // an argument about whose numbers are right.
            'meta' => $this->metrics->forOrganization($organization),
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
            'data' => (new PlatformOrganizationResource($organization->fresh('plan')))->resolve(),
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
            'data' => (new PlatformOrganizationResource($updated->load('plan')))->resolve(),
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
            'data' => (new PlatformOrganizationResource($updated->load('plan')))->resolve(),
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
            'data' => (new PlatformOrganizationResource($updated->load('plan')))->resolve(),
        ]);
    }

    /**
     * Move a tenant onto a plan, or off plans entirely.
     */
    public function changePlan(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['present', 'nullable', 'string', 'exists:plans,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $plan = $data['plan_id'] === null
            ? null
            : Plan::query()->findOrFail($data['plan_id']);

        $result = $this->tenants->changePlan(
            $organization,
            $plan,
            $this->currentUser(),
            $data['reason'] ?? null,
        );

        return response()->json([
            'message' => $result['breaches'] === []
                ? 'The plan has been changed.'
                : 'The plan has been changed. This organization is now over some of its limits; '
                    .'it keeps what it has and cannot add more.',
            'data' => (new PlatformOrganizationResource($result['organization']))->resolve(),
            // Reported rather than refused, so the commercial decision can
            // complete and the conversation happens now rather than at the
            // customer's next click.
            'meta' => ['breaches' => $result['breaches']],
        ]);
    }

    public function setTrial(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'trial_ends_at' => ['present', 'nullable', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $updated = $this->tenants->setTrialEnd(
            $organization,
            $data['trial_ends_at'] === null ? null : CarbonImmutable::parse($data['trial_ends_at']),
            $this->currentUser(),
            $data['reason'] ?? null,
        );

        return response()->json([
            'data' => (new PlatformOrganizationResource($updated->load('plan')))->resolve(),
        ]);
    }

    /**
     * A negotiated exception on one tenant's caps or features.
     */
    public function setOverrides(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'limits' => ['sometimes', 'nullable', 'array'],
            // Keys validated against the registry rather than accepted freely: a
            // typo in an override key would silently do nothing, which is the
            // worst outcome for something an operator believes they configured.
            'limits.*' => ['nullable', 'integer', 'min:0'],
            'features' => ['sometimes', 'nullable', 'array'],
            'features.*' => ['boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        foreach (array_keys($data['limits'] ?? []) as $key) {
            abort_unless(
                in_array($key, PlanFeature::limitKeys(), true),
                422,
                sprintf('There is no limit named "%s".', $key),
            );
        }

        foreach (array_keys($data['features'] ?? []) as $key) {
            abort_unless(
                PlanFeature::exists($key),
                422,
                sprintf('There is no feature named "%s".', $key),
            );
        }

        $updated = $this->tenants->setOverrides(
            $organization,
            array_key_exists('limits', $data) ? ($data['limits'] ?? []) : null,
            array_key_exists('features', $data) ? ($data['features'] ?? []) : null,
            $this->currentUser(),
            $data['reason'] ?? null,
        );

        return response()->json([
            'data' => (new PlatformOrganizationResource($updated->load('plan')))->resolve(),
            'meta' => ['usage' => $this->metrics->forOrganization($updated)['usage']],
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
