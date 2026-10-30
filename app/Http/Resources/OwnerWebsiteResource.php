<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * A client's own site, as the client portal shows it: the site itself, its
 * details and its homes, and nothing of how the platform runs it — not the
 * readiness checklist, which names servers and settings, and not the booking
 * rules, which are the management company's.
 */
class OwnerWebsiteResource extends WebsiteResource
{
    /** What the platform's own screen reads and a client does not. */
    private const PLATFORM_ONLY = ['readiness', 'hold_minutes', 'request_hours'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_diff_key(parent::toArray($request), array_flip(self::PLATFORM_ONLY));
    }
}
