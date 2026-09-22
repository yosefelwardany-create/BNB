<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\Channels;

use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\DataObjects\ChannelAvailabilityUpdate;
use App\Domain\Integrations\DataObjects\ChannelListingPayload;
use App\Domain\Integrations\DataObjects\ChannelRateUpdate;
use App\Domain\Integrations\DataObjects\ChannelSyncResult;

/**
 * The organization's own booking engine, modelled as a channel.
 *
 * Treating direct bookings as a channel — rather than as a special case —
 * means channel mix reporting, commission handling and reservation sourcing
 * all work uniformly, and a direct booking is never accidentally excluded from
 * a report that says "all channels".
 *
 * Nothing needs to be pushed anywhere: the booking engine reads the platform's
 * availability and pricing directly, so every push is a no-op success.
 */
class DirectBookingAdapter extends AbstractChannelAdapter
{
    public function key(): string
    {
        return 'direct';
    }

    public function displayName(): string
    {
        return 'Direct booking';
    }

    public function isLive(): bool
    {
        // This is a genuine, fully functional channel: the booking engine is
        // part of the product.
        return true;
    }

    public function capabilities(): array
    {
        return [
            self::CAPABILITY_AVAILABILITY,
            self::CAPABILITY_PRICING,
            self::CAPABILITY_RESTRICTIONS,
            self::CAPABILITY_IMPORT_RESERVATIONS,
            self::CAPABILITY_MODIFY_RESERVATIONS,
            self::CAPABILITY_CANCEL_RESERVATIONS,
            self::CAPABILITY_MESSAGING,
        ];
    }

    public function publishListing(ChannelListing $listing, ChannelListingPayload $payload): ChannelSyncResult
    {
        // The booking engine renders from the listing record itself.
        return ChannelSyncResult::success($listing->getKey());
    }

    public function pushAvailability(ChannelListing $listing, ChannelAvailabilityUpdate $update): ChannelSyncResult
    {
        return ChannelSyncResult::success($listing->getKey(), ['nights' => count($update->days)]);
    }

    public function pushRates(ChannelListing $listing, ChannelRateUpdate $update): ChannelSyncResult
    {
        return ChannelSyncResult::success($listing->getKey(), ['nights' => count($update->nightlyRates)]);
    }

    public function cancelReservation(ChannelListing $listing, string $externalReservationId, ?string $reason = null): ChannelSyncResult
    {
        // The reservation lives here; cancelling it is already done.
        return ChannelSyncResult::success($externalReservationId);
    }
}
