<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Organization\Models\Organization;
use App\Domain\Owners\Services\ClientAccounts;
use App\Domain\Users\Models\User;
use App\Domain\Websites\Models\Website;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The account's client, and their own site, or nothing.
 *
 * The client portal's site routes are the one place a client changes
 * something, so they are held to two things. Who: the account's own client
 * (or the platform owner looking through their eyes) — any other login of
 * the account, a cleaner or a read-only one, gets what it gets everywhere in
 * the portal, a refusal. And which site: only a client site of that account
 * is found; another account's, or the platform's brand site, is not there at
 * all.
 */
class EnsureOwnWebsite
{
    public function __construct(private readonly ClientAccounts $clients) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->attributes->get('organization');
        $user = $request->user();

        if (! $organization instanceof Organization || ! $user instanceof User) {
            throw new NotFoundHttpException;
        }

        // The same test as every other portal screen.
        if ($this->clients->portalOwnerFor($user, $organization) === null) {
            throw new AccessDeniedHttpException('This account is not linked to a client. Portal access is granted by the management company.');
        }

        if ($request->route()?->hasParameter('website')) {
            $website = $request->route('website');
            $id = $website instanceof Website ? $website->getKey() : $website;

            $own = is_string($id) && Website::query()
                ->whereKey($id)
                ->where('kind', Website::KIND_CLIENT)
                ->where('organization_id', $organization->getKey())
                ->exists();

            if (! $own) {
                throw new NotFoundHttpException;
            }
        }

        return $next($request);
    }
}
