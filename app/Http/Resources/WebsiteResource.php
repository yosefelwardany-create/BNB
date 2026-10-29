<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteListing;
use App\Domain\Websites\Support\WebsiteMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A booking website as the platform owner manages it.
 *
 * Expects `organization` and `listings.listing.property`, `listings.organization`
 * loaded by the controller, which reads them across client accounts.
 *
 * @mixin Website
 */
class WebsiteResource extends JsonResource
{
    /**
     * The settings an operator edits, in the order the screen shows them.
     *
     * @var list<string>
     */
    public const SETTINGS = [
        'heading', 'tagline', 'primary_colour', 'accent_colour',
        'contact_email', 'contact_phone', 'address_line', 'instagram_url', 'facebook_url',
    ];

    public function toArray(Request $request): array
    {
        $settings = [];

        foreach (self::SETTINGS as $key) {
            $settings[$key] = $this->setting($key);
        }

        foreach (WebsiteMedia::KINDS as $kind) {
            $settings[$kind.'_url'] = WebsiteMedia::url($this->resource, $kind);
        }

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'kind' => $this->kind,
            'organization_id' => $this->organization_id,
            'organization_name' => $this->relationLoaded('organization') ? $this->organization?->name : null,
            'status' => $this->status,
            'settings' => $settings,
            'hold_minutes' => $this->hold_minutes,
            'request_hours' => $this->request_hours,
            'listings' => $this->relationLoaded('listings')
                ? $this->listings->map(fn (WebsiteListing $row): array => [
                    'listing_id' => $row->listing_id,
                    'title' => $row->listing?->displayTitle(),
                    'organization_id' => $row->organization_id,
                    'organization_name' => $row->organization?->name,
                    'is_featured' => $row->is_featured,
                ])->values()->all()
                : [],
            'public_url' => $this->url(),
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
