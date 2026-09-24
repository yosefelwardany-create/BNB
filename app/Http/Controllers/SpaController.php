<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the admin application's entry point for its client-side routes.
 *
 * Where this does and does not apply is worth being exact about, because the
 * obvious assumption is wrong in both directions.
 *
 * **Production never reaches it.** Caddy handles `/app/*` with a `try_files`
 * fallback and serves the bundle directly, so the request does not touch PHP
 * at all. See `docker/Caddyfile`.
 *
 * **`php artisan serve` cannot use it either**, and not for a reason this code
 * can fix. Once `public/app/index.html` exists, PHP's built-in web server
 * resolves `/app/reservations` against that directory and sets
 * `SCRIPT_NAME=/app/index.html`. Symfony then computes a base URL of `/app`
 * and a path of `/reservations`, so the router looks for a route by that name
 * and finds none. Nothing in the application sees `/app/reservations` to route
 * it. Use the Vite dev server (`npm run dev`) for local development; it serves
 * the SPA with its own fallback and proxies the API.
 *
 * What it is for is the case in between: a deployment behind a web server that
 * passes unknown paths to PHP without an SPA rewrite — a stock nginx or Apache
 * virtual host. There, `/app/` works and `/app/reservations` would return a
 * Laravel 404 the moment somebody refreshes or follows a link into the
 * application, which reads as a broken product rather than a missing rewrite
 * rule. This turns that into the working application.
 *
 * Real files are never affected: every web server serves an existing file under
 * `public/` before routing, so only paths with nothing behind them arrive here.
 */
class SpaController extends Controller
{
    public function __invoke(): BinaryFileResponse|Response
    {
        $index = (string) config('pms.admin.index_path');

        if (! is_file($index)) {
            // A missing build is a setup step nobody performed, not a fault in
            // the application, and naming the step saves the reader searching a
            // server log for a stack trace that is not there.
            return response(
                "The admin application has not been built.\n\n"
                ."    cd frontend && npm ci && npm run build\n\n"
                .'The API is unaffected and is available under /api/v1.',
                503,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        // Never cached: this file names the hashed asset bundles, so a stale
        // copy points at assets a later build has already replaced.
        return response()->file($index, [
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }
}
