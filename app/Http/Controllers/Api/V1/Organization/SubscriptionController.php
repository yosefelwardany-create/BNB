<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organization;

use App\Domain\Platform\Models\ImpersonationSession;
use App\Domain\Platform\Models\PlatformAnnouncement;
use App\Domain\Platform\Services\PlanEnforcement;
use App\Domain\Platform\Services\PlatformSettings;
use App\Http\Controllers\Controller;
use App\Http\Resources\Platform\ImpersonationSessionResource;
use App\Http\Resources\Platform\PlanResource;
use Illuminate\Http\JsonResponse;

/**
 * What a tenant can see about its own subscription.
 *
 * The honest complement to the platform console. A platform that can meter a
 * customer and cap what they do, without showing them what their plan is or how
 * close to a limit they are, produces a refusal out of nowhere at the worst
 * moment — and the customer's only recourse is a support ticket asking what
 * happened.
 *
 * So the same numbers the console shows the operator are served here, from the
 * same service. A support conversation cannot become an argument about whose
 * figures are right if there is only one set of figures.
 *
 * Three things are deliberately absent: the platform's private notes, the
 * operator's wording of a suspension reason, and anything about other tenants.
 */
class SubscriptionController extends Controller
{
    public function __construct(private readonly PlanEnforcement $plans) {}

    /**
     * The plan, what is in use against it, and which features are on.
     *
     * No permission gate. Every member of an organization may see the plan they
     * are working inside; a cleaner who cannot add a property is entitled to
     * know that the reason is a cap rather than a fault.
     */
    public function show(): JsonResponse
    {
        $organization = $this->organization();

        return response()->json([
            'data' => [
                'plan' => $organization->plan === null
                    ? null
                    : (new PlanResource($organization->plan))->resolve(),

                'status' => $organization->status->value,
                'status_label' => $organization->status->label(),
                'trial_ends_at' => $organization->trial_ends_at?->toIso8601String(),
                'trial_has_expired' => $organization->trialHasExpired(),

                'usage' => $this->plans->usage($organization),
                'features' => $this->plans->features($organization),

                // Said plainly rather than implied by an absent plan. An
                // organization on no plan is not broken and is not on a
                // free tier that might quietly cut off; nothing is metered.
                'is_metered' => $organization->plan !== null,
            ],
            'meta' => [
                'support_email' => app(PlatformSettings::class)->get('support_email'),
            ],
        ]);
    }

    /**
     * Notices from the platform that apply to this organization.
     */
    public function announcements(): JsonResponse
    {
        $organization = $this->organization();

        $announcements = PlatformAnnouncement::query()
            ->live()
            ->orderByDesc('level')
            ->orderByDesc('starts_at')
            ->get()
            ->filter(fn (PlatformAnnouncement $a): bool => $a->appliesTo($organization))
            ->values();

        return response()->json([
            'data' => $announcements->map(fn (PlatformAnnouncement $a): array => [
                'id' => $a->id,
                'title' => $a->title,
                'body' => $a->body,
                'level' => $a->level,
                'is_dismissible' => (bool) $a->is_dismissible,
                'starts_at' => $a->starts_at?->toIso8601String(),
                'ends_at' => $a->ends_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'maintenance_notice' => app(PlatformSettings::class)->get('maintenance_notice'),
            ],
        ]);
    }

    /**
     * Every time the platform operator looked inside this account.
     *
     * The customer's side of impersonation, and the reason the feature is
     * defensible at all. Who, when, why, for how long, how much they read, and
     * that it was read-only — visible to the customer without asking anybody.
     *
     * Gated on `organization.view` rather than being open to every member: it
     * names individual staff accounts, which is the organization's own business
     * and not every seat's.
     */
    public function supportSessions(): JsonResponse
    {
        $sessions = ImpersonationSession::query()
            ->where('organization_id', $this->organization()->getKey())
            ->with(['platformUser', 'targetUser'])
            ->orderByDesc('started_at')
            ->paginate($this->perPage());

        return ImpersonationSessionResource::collection($sessions)
            ->additional([
                'meta' => [
                    'notice' => 'Support sessions are read-only. Nothing in your account can be '
                        .'changed through one.',
                ],
            ])
            ->response();
    }
}
