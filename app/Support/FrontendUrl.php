<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A link to a page of the web app, for an email or a redirect.
 *
 * The app is served under /app. Where it shares the API's host, as it does in
 * production, the configured address is that host alone, so a link built
 * straight on it — an invitation, a password reset — landed on a 404. Where
 * it is served on its own, as the development server is, it is used as it is.
 */
final class FrontendUrl
{
    public static function to(string $path): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        if ($base === rtrim((string) config('app.url'), '/')) {
            $base .= '/app';
        }

        return $base.'/'.ltrim($path, '/');
    }
}
