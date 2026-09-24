<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Platform\Services\PlanEnforcement;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level plan gate.
 *
 *   Route::post(...)->middleware('feature:channels');
 *
 * Declarative for the same reason the permission gate is: what a plan includes
 * belongs next to the route it governs, where somebody reading the routes can
 * see it, rather than buried in whichever controller happened to remember.
 *
 * Distinct from `permission:` on purpose, and both usually apply. A permission
 * answers "is this person allowed to do it"; a feature answers "has this company
 * bought it". Conflating them produces one of two bad outcomes: a customer whose
 * plan lacks a feature is told they lack permission and asks their administrator
 * to fix something they cannot fix, or an unauthorised user is told to upgrade.
 *
 * Runs after the tenant resolves. Outside a tenant it does nothing, because
 * there is no plan to consult — the platform console's own routes are gated by
 * the platform-admin middleware instead.
 */
class EnsurePlanFeature
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly PlanEnforcement $plans,
    ) {}

    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        if ($features === [] || ! $this->tenancy->hasTenant()) {
            return $next($request);
        }

        $organization = $this->tenancy->organizationOrFail();

        // Any of them suffices, matching how the permission gate reads, so a
        // route reachable under either of two features says so in one place.
        foreach ($features as $feature) {
            if ($organization->allows($feature)) {
                return $next($request);
            }
        }

        // Throws the 402 carrying the plan's name and what the feature does.
        $this->plans->assertHasFeature($features[0], $organization);

        return $next($request);
    }
}
