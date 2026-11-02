<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * A client's own site, as the client portal shows it: the site itself, its
 * details and its homes, and nothing of how the platform runs it — not the
 * readiness checklist, which names servers and settings, not the booking
 * rules, which are the management company's, and not where the site is shown
 * on the client's store, which the platform sets up.
 */
class OwnerWebsiteResource extends WebsiteResource
{
    /** What the platform's own screen reads and a client does not. */
    private const PLATFORM_ONLY = ['readiness', 'hold_minutes', 'request_hours', 'embed_origins', 'embed'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $site = array_diff_key(parent::toArray($request), array_flip(self::PLATFORM_ONLY));
        $site['listings'] = array_map(
            fn (array $row): array => array_diff_key($row, ['store_url' => true]),
            $site['listings'],
        );

        return $site;
    }
}
