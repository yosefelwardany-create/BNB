<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Support\PlanFeature;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Binds the organization the request acts on, and verifies the authenticated
 * user is actually a member of it.
 *
 * Resolution order:
 *   1. An `X-Organization` header (id or slug) — used by the admin SPA, which
 *      lets a user switch between the companies they work for.
 *   2. The organization encoded in the API token's abilities (`organization:{id}`),
 *      so a machine token can never act outside the tenant it was issued for.
 *   3. The user's single active membership.
 *
 * If the user belongs to more than one organization and gave no hint, the
 * request is rejected rather than guessed at.
 */
class ResolveOrganization
{
    public function __construct(private readonly TenantContext $tenancy) {}

    /**
     * @param  string|null  $mode  Pass `optional` to allow a request with no
     *                             organization through. For endpoints that
     *                             describe the session rather than a tenant's
     *                             data: a platform administrator holds no
     *                             membership anywhere, so refusing would make
     *                             the console unreachable — the token is
     *                             issued, the session call is refused, and the
     *                             operator is bounced back to sign-in.
     *
     *                             Everything a tenant is checked for still
     *                             applies whenever one *is* resolved.
     */
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $organization = $this->resolve($request, $user);

        if ($organization === null) {
            if ($mode === 'optional') {
                // Cleared, not merely left alone. The context is a singleton
                // for the life of the process, so on a persistent runtime —
                // FrankenPHP, Octane, a queue worker — whatever the previous
                // request bound would still be set, and this request would
                // silently answer for somebody else's tenant.
                $this->tenancy->clear();

                return $next($request);
            }

            throw new AccessDeniedHttpException('No organization could be resolved for this request.');
        }

        if (! $organization->isOperational() && ! $user->isPlatformAdmin()) {
            throw new AccessDeniedHttpException(
                sprintf('This organization is %s and cannot be accessed.', $organization->status->value)
            );
        }

        // An organization may require a second factor of everybody working in
        // it. Enforced here rather than at sign-in, because a person may work
        // for two companies and only one of them require it — refusing the
        // whole sign-in would lock them out of the company that does not.
        //
        // The enrolment endpoints sit outside this middleware, so somebody
        // caught by this can always fix it.
        // Two conditions, and the distinction matters: the plan feature grants
        // the *ability* to impose this, and the organization's own setting is
        // whether it has. Treating the feature alone as the requirement would
        // impose it on every organization with no plan, since an unmetered
        // organization is allowed everything — which would lock out every
        // customer on a fresh install.
        $requiresMfa = $organization->setting('security.require_mfa', false) === true
            && $organization->allows(PlanFeature::REQUIRE_MFA);

        if ($requiresMfa && ! $user->mfa_enabled) {
            throw new AccessDeniedHttpException(sprintf(
                '%s requires two-factor authentication. Enrol at /api/v1/auth/mfa/begin, '
                .'then sign in again.',
                $organization->name,
            ));
        }

        $this->tenancy->set($organization);

        $request->attributes->set('organization', $organization);

        return $next($request);
    }

    private function resolve(Request $request, User $user): ?Organization
    {
        $hint = $this->explicitHint($request);

        if ($hint !== null) {
            $organization = Organization::query()
                ->where('id', $hint)
                ->orWhere('slug', $hint)
                ->first();

            if ($organization === null) {
                throw new AccessDeniedHttpException('The requested organization does not exist.');
            }

            $this->assertMembership($user, $organization);

            return $organization;
        }

        $memberships = Membership::query()
            ->withoutGlobalScope('organization')
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->get();

        if ($memberships->isEmpty()) {
            // Platform administrators may operate without a membership, but
            // they still have to name the tenant explicitly.
            return null;
        }

        if ($memberships->count() > 1) {
            throw new ConflictHttpException(
                'This account belongs to several organizations. Send an X-Organization header to choose one.'
            );
        }

        return Organization::query()->find($memberships->first()->organization_id);
    }

    /**
     * An organization named by the request itself.
     */
    private function explicitHint(Request $request): ?string
    {
        $header = $request->header('X-Organization');

        if (is_string($header) && $header !== '') {
            return $header;
        }

        $token = $request->user()?->currentAccessToken();

        if ($token !== null && method_exists($token, 'can')) {
            foreach ((array) ($token->abilities ?? []) as $ability) {
                if (is_string($ability) && str_starts_with($ability, 'organization:')) {
                    return substr($ability, strlen('organization:'));
                }
            }
        }

        return null;
    }

    private function assertMembership(User $user, Organization $organization): void
    {
        if ($user->isPlatformAdmin()) {
            return;
        }

        $isMember = Membership::query()
            ->withoutGlobalScope('organization')
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organization->getKey())
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            // Deliberately the same message as "does not exist" so that the
            // endpoint cannot be used to discover which organizations exist.
            throw new AccessDeniedHttpException('The requested organization does not exist.');
        }
    }
}
