<?php

declare(strict_types=1);

namespace App\Domain\Channels\Listeners;

use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Properties\Events\PropertyArchived;

/**
 * An archived property stops being sent to its channels.
 *
 * Archiving already takes the property's listings off sale here. Its channel
 * mappings stayed active, so the next push sent the calendar and prices of a
 * property nobody manages any more — and, for one with no price, failed on
 * every run. Deactivated rather than deleted, as a mapping stopped by hand is:
 * bookings that came through it still point at it.
 *
 * The channel keeps what it was last sent. Closing the nights there is a
 * decision about that channel, made on it, not a side effect of tidying up
 * this side.
 */
class StopSellingArchivedProperty
{
    public function handle(PropertyArchived $event): void
    {
        ChannelListing::query()
            ->where('property_id', $event->property->getKey())
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }
}
