<?php

declare(strict_types=1);

namespace App\Domain\Websites\Support;

use App\Domain\Websites\Models\Website;

/**
 * Where a link on a site's page goes: to the site's own page, or, on a page
 * shown in a frame on the client's store, to the framed version of it, so a
 * guest who started in the pop-up stays in the pop-up.
 */
final class SiteLinks
{
    /**
     * @param  bool|string  $embed  false on the site itself; "piece" or "popup" in a frame
     */
    public static function to(Website $site, string $path, bool|string $embed): string
    {
        return $embed === false || $embed === '' ? $site->url($path) : $site->url('embed/'.ltrim($path, '/'));
    }
}
