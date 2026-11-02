<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Support\SiteFrames;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every answer of a site's embed pages may be shown in a frame on the site's
 * store, not only the pages that were found.
 *
 * The server forbids framing on every response (docker/Caddyfile). Without
 * this, a "not found" for a mistyped home, a site taken offline, or "too many
 * requests" would show on the store as the browser's refusal to connect,
 * which says nothing and points at the wrong thing.
 */
class FramedByStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->headers->has('Content-Security-Policy')) {
            return $response;
        }

        $slug = $request->route('site');
        $site = is_string($slug) ? Website::query()->where('slug', $slug)->first() : null;

        $response->headers->add($site === null
            ? ['Content-Security-Policy' => "frame-ancestors 'none'", 'X-Robots-Tag' => 'noindex, nofollow']
            : SiteFrames::headers($site));

        return $response;
    }
}
