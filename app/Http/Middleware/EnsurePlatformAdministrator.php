<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Platform\Services\Impersonation;
use App\Domain\Platform\Services\PlatformSettings;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The gate on the platform console.
 *
 * Three things, and each of them matters:
 *
 * **It requires a platform administrator.** Not a permission, not a role — the
 * `is_platform_admin` flag on the user. Platform administration is deliberately
 * outside the tenant permission system, because every permission in that system
 * is grantable by a tenant's own administrator, and a tenant must never be able
 * to grant its way into governing the platform.
 *
 * **It clears the tenant.** The console reads across every organization, so
 * running inside one would silently filter half its answers to a single tenant.
 * Clearing is safer than never setting: the SPA sends `X-Organization` on every
 * request, so without this a console request made from a signed-in session would
 * inherit whichever tenant the operator last looked at.
 *
 * **It refuses an impersonation token.** A borrowed session must not be able to
 * reach the console that issued it. Without this check, an operator who
 * impersonated a customer would be holding a token that could start another
 * impersonation — the one escalation path this design has to close.
 *
 * Failures are 404, not 403. The existence and shape of the platform console is
 * not something a tenant's API key should be able to map by watching which
 * paths answer differently.
 */
class EnsurePlatformAdministrator
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isPlatformAdmin()) {
            throw new NotFoundHttpException;
        }

        if (Impersonation::isImpersonationToken($user->currentAccessToken())) {
            throw new NotFoundHttpException;
        }

        // A platform administrator without a second factor is one stolen
        // password away from every customer's data. Refused with a 403 and an
        // instruction rather than the 404 used above: this person *is* an
        // administrator, so nothing is disclosed by telling them why, and a
        // silent 404 would leave them believing their access was revoked.
        if (
            app(PlatformSettings::class)->get('require_mfa_for_platform_admins', true) === true
            && ! $user->mfa_enabled
        ) {
            throw new AccessDeniedHttpException(
                'The platform console requires two-factor authentication. '
                .'Enrol at /api/v1/auth/mfa/begin, then sign in again.',
            );
        }

        // Nothing here belongs to a tenant.
        $this->tenancy->clear();

        return $next($request);
    }
}
