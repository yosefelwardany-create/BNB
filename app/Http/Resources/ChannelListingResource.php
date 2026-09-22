<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Channels\Models\ChannelListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChannelListing
 */
class ChannelListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_account_id' => $this->channel_account_id,
            'listing_id' => $this->listing_id,
            'property_id' => $this->property_id,
            'account' => new ChannelAccountResource($this->whenLoaded('account')),
            'listing' => new ListingResource($this->whenLoaded('listing')),

            'external_listing_id' => $this->external_listing_id,
            'external_name' => $this->external_name,
            'external_url' => $this->external_url,

            'status' => $this->status,
            'is_active' => (bool) $this->is_active,

            'rate_adjustment_basis_points' => (int) $this->rate_adjustment_basis_points,
            'commission_basis_points' => $this->commissionBasisPoints(),

            // The gap between what we intend and what the channel last heard.
            // This is the answer to "why is this still bookable there?", so it
            // is first-class rather than buried in a sync log.
            'availability_dirty' => (bool) $this->availability_dirty,
            'rates_dirty' => (bool) $this->rates_dirty,
            'availability_pushed_at' => $this->availability_pushed_at?->toIso8601String(),
            'rates_pushed_at' => $this->rates_pushed_at?->toIso8601String(),

            'consecutive_failures' => (int) $this->consecutive_failures,

            // Repeated failure is surfaced rather than retried silently: after
            // a few the cause is almost never transient.
            'is_failing' => $this->isFailing(),
            'last_error' => $this->last_error,
            'last_error_at' => $this->last_error_at?->toIso8601String(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
