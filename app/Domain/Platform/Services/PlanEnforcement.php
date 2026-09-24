<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Exceptions\PlanLimitReachedException;
use App\Domain\Platform\Support\PlanFeature;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\Unit;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\Membership;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * What a tenant is actually allowed to do on its plan.
 *
 * This is the half of plans that usually gets skipped. Storing a cap is easy;
 * refusing the record that exceeds it is the part that makes the cap real, and
 * a platform whose limits are only ever displayed has no limits.
 *
 * Two rules throughout:
 *
 *  - **Refuse the creation, never delete the excess.** A tenant that drops to a
 *    smaller plan while holding sixty properties keeps all sixty and cannot add
 *    a sixty-first. Deleting to fit a plan would destroy records that
 *    reservations, statements and ledger entries point at.
 *
 *  - **Count what exists now, not a cached number.** A usage counter that
 *    drifts is worse than no counter: it either blocks a customer who is
 *    within their plan or lets one past it, and nobody can tell which until
 *    they complain.
 */
class PlanEnforcement
{
    public function __construct(private readonly TenantContext $tenancy) {}

    /**
     * Refuse if adding one more of something would exceed the plan.
     *
     * @throws PlanLimitReachedException
     */
    public function assertCanAdd(string $limitKey, ?Organization $organization = null, int $adding = 1): void
    {
        $organization ??= $this->tenancy->organizationOrFail();

        $limit = $organization->limit($limitKey);

        if ($limit === null) {
            return;
        }

        $current = $this->usageFor($limitKey, $organization);

        if ($current + $adding <= $limit) {
            return;
        }

        throw PlanLimitReachedException::capReached(
            $limitKey,
            $current,
            $limit,
            $organization->plan?->name,
        );
    }

    /**
     * Refuse if the plan does not include a feature.
     *
     * @throws PlanLimitReachedException
     */
    public function assertHasFeature(string $feature, ?Organization $organization = null): void
    {
        $organization ??= $this->tenancy->organizationOrFail();

        if ($organization->allows($feature)) {
            return;
        }

        throw PlanLimitReachedException::featureUnavailable(
            $feature,
            PlanFeature::all()[$feature] ?? $feature,
            $organization->plan?->name,
        );
    }

    /**
     * Everything at once: what is in use, what the cap is, and how close.
     *
     * Used by the tenant's own settings screen and by the platform console, so
     * both see the same numbers and a support conversation cannot become an
     * argument about whose figure is right.
     *
     * @return array<string, array{used: int, limit: int|null, remaining: int|null, at_limit: bool}>
     */
    public function usage(?Organization $organization = null): array
    {
        $organization ??= $this->tenancy->organizationOrFail();

        $limits = $organization->effectiveLimits();
        $usage = [];

        foreach (PlanFeature::limitKeys() as $key) {
            $used = $this->usageFor($key, $organization);
            $limit = $limits[$key] ?? null;

            $usage[$key] = [
                'used' => $used,
                'limit' => $limit,
                'remaining' => $limit === null ? null : max(0, $limit - $used),
                'at_limit' => $limit !== null && $used >= $limit,
            ];
        }

        return $usage;
    }

    /**
     * The features in force, and where each answer came from.
     *
     * The source matters to whoever is answering a support question: "your plan
     * does not include this" and "this was switched off for your account" need
     * different replies.
     *
     * @return array<string, array{enabled: bool, source: string}>
     */
    public function features(?Organization $organization = null): array
    {
        $organization ??= $this->tenancy->organizationOrFail();

        $overrides = $organization->feature_overrides ?? [];
        $result = [];

        foreach (PlanFeature::keys() as $key) {
            $result[$key] = [
                'enabled' => $organization->allows($key),
                'source' => match (true) {
                    array_key_exists($key, $overrides) => 'override',
                    $organization->plan !== null => 'plan',
                    default => 'unmetered',
                },
            ];
        }

        return $result;
    }

    /**
     * How many of a thing the organization currently has.
     *
     * Counted without the tenant scope and filtered explicitly, because the
     * platform console asks this about organizations other than the one it is
     * acting in — and a scoped count would silently answer about the wrong one.
     */
    private function usageFor(string $limitKey, Organization $organization): int
    {
        $id = $organization->getKey();

        return match ($limitKey) {
            'max_properties' => Property::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $id)
                ->whereNull('deleted_at')
                ->count(),

            'max_units' => Unit::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $id)
                ->count(),

            'max_listings' => Listing::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $id)
                ->whereNotNull('published_at')
                ->count(),

            'max_users' => Membership::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $id)
                ->where('status', 'active')
                ->count(),

            // Per calendar month, counted in the organization's own timezone —
            // a cap that resets at midnight UTC resets in the middle of the
            // working day for half the world.
            'max_reservations_per_month' => Reservation::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $id)
                ->where('created_at', '>=', CarbonImmutable::now($organization->timezone)->startOfMonth())
                ->count(),

            default => 0,
        };
    }
}
