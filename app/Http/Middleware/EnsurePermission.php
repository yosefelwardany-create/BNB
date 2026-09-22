<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Route-level permission gate.
 *
 *   Route::get(...)->middleware('permission:reservations.view');
 *   Route::post(...)->middleware('permission:reservations.create,reservations.update');
 *
 * Several permissions are treated as "any of". Controllers still authorize the
 * individual record through a policy; this middleware is the coarse filter that
 * keeps unauthorised callers out of the controller entirely.
 */
class EnsurePermission
{
    public function __construct(private readonly AccessControl $access) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AccessDeniedHttpException('Unauthenticated.');
        }

        if ($permissions === [] || $this->access->allowsAny($user, $permissions)) {
            return $next($request);
        }

        throw new AccessDeniedHttpException(sprintf(
            'This action requires the %s permission.',
            implode(' or ', $permissions),
        ));
    }
}
