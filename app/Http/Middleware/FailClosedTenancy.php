<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes tenant scoping fail closed for the lifetime of an HTTP request.
 *
 * With no tenant bound, the organization scope used to switch itself off, so a
 * route that forgot the `organization` middleware answered with every
 * organization's rows. Inside a request that is never acceptable: a
 * tenant-scoped query with no account context is refused with a 403 instead.
 * Routes that legitimately read across organizations (platform administration,
 * the guest portal before it binds its reservation's tenant, inbound webhooks)
 * already say so with withoutScope() or withoutGlobalScope('organization'),
 * which this does not touch.
 *
 * Prepended to the API group so that it covers routes with no other middleware.
 */
class FailClosedTenancy
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Cleared as well: on a persistent runtime the singleton outlives the
        // request, and whatever the previous request bound must not leak.
        $this->tenancy->clear();
        $this->tenancy->enforce(true);

        return $next($request);
    }
}
