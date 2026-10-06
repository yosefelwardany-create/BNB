<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Users\Models\Membership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A client login changes nothing.
 *
 * The client role holds no staff permission, so every write route already
 * refuses a client at its permission gate. This is the second layer, for the
 * routes that have no gate today or forget one tomorrow: any request that
 * resolved a client membership and is not a read is refused here, whatever the
 * route.
 *
 * Authentication and account security (sign-in, sign-out, password, second
 * factor, profile) are untouched: those routes sit outside the `organization`
 * middleware, so no membership is ever resolved for them and this middleware
 * has nothing to say.
 */
class EnsureClientReadOnly
{
    private const READS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        $membership = $request->attributes->get('membership');

        if (! $membership instanceof Membership || $membership->default_portal !== 'owner') {
            return $next($request);
        }

        if (! in_array(strtoupper($request->getMethod()), self::READS, true)) {
            throw new AccessDeniedHttpException(
                'Your account is read-only. Changes to properties, calendars, bookings and settings are made by the management company.'
            );
        }

        return $next($request);
    }
}
