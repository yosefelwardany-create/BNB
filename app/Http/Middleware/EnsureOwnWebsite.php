<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Organization\Models\Organization;
use App\Domain\Websites\Models\Website;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A client's own site, or nothing.
 *
 * The client portal's site routes take a site by its id. Only a client site of
 * the account the request is for is found; another account's site, or the
 * platform's brand site, is not there at all.
 */
class EnsureOwnWebsite
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->attributes->get('organization');
        $website = $request->route('website');
        $id = $website instanceof Website ? $website->getKey() : $website;

        $own = $organization instanceof Organization && is_string($id) && Website::query()
            ->whereKey($id)
            ->where('kind', Website::KIND_CLIENT)
            ->where('organization_id', $organization->getKey())
            ->exists();

        if (! $own) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
