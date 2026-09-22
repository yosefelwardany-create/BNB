<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property \App\Domain\Channels\Models\ChannelListing $resource
 */
class ChannelListingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mapping = $this->resource;

        return [
            'id' => $mapping->getKey(),
            'listing_id' => $mapping->listing_id,
            'channel_account_id' => $mapping->channel_account_id,
            'channel' => $mapping->channel,
            'external_listing_id' => $mapping->external_listing_id,
            'external_unit_id' => $mapping->external_unit_id,
            'mapping_status' => $mapping->mapping_status,
            'sync_status' => $mapping->sync_status,
            'last_synced_at' => $mapping->last_synced_at?->toIso8601String(),
            'last_error' => $mapping->last_error,
            'consecutive_failures' => (int) $mapping->consecutive_failures,
            'is_active' => $mapping->is_active,
        ];
    }
}
