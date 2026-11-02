<?php

declare(strict_types=1);

namespace App\Domain\Websites\Support;

use App\Domain\Websites\Models\Website;

/**
 * Who may show a site's pieces in a frame.
 *
 * The server sends X-Frame-Options: DENY with every response
 * (docker/Caddyfile). A frame-ancestors policy is what browsers obey in its
 * place, so the pages a store frames name exactly the store's addresses, and
 * a site with none is framed by nobody.
 */
final class SiteFrames
{
    /**
     * @return array<string, string>
     */
    public static function headers(Website $site, bool $private = false): array
    {
        $origins = $site->embedOrigins();

        return array_filter([
            'Content-Security-Policy' => 'frame-ancestors '.($origins === [] ? "'none'" : implode(' ', $origins)),
            // Parts of the store's pages, not pages of their own.
            'X-Robots-Tag' => 'noindex, nofollow',
            // A checkout or a booking is one guest's.
            'Cache-Control' => $private ? 'no-store, private' : null,
        ]);
    }
}
