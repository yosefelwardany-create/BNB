<?php

declare(strict_types=1);

namespace App\Domain\Channels\Listeners;

use App\Domain\Channels\Services\ChannelSynchroniser;
use App\Domain\Reservations\Events\ReservationCancelled;
use App\Domain\Reservations\Events\ReservationConfirmed;
use App\Domain\Reservations\Events\ReservationCreated;
use App\Domain\Reservations\Events\ReservationModified;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the channels in step with the calendar.
 *
 * Deliberately synchronous and deliberately cheap: it sets a flag and a date
 * window, nothing more. The actual push is drained by the scheduler.
 *
 * That split is the whole point. Pushing inline would mean a guest's booking
 * waits on however many OTAs the listing is published to, and an OTA being
 * slow — or down — could fail a reservation that has already taken the guest's
 * money. Marking is a single update; the consequences are somebody else's
 * problem, a few seconds later.
 *
 * A booking arriving *from* a channel still marks every channel dirty,
 * including the one it came from. The others need to know the dates are gone,
 * and the originating channel already knows but will simply skip an unchanged
 * calendar.
 */
class MarkChannelsDirty
{
    public function __construct(
        private readonly ChannelSynchroniser $sync,
        private readonly TenantContext $tenancy,
    ) {}

    public function handleConfirmed(ReservationConfirmed $event): void
    {
        $this->mark($event->reservation);
    }

    public function handleCreated(ReservationCreated $event): void
    {
        // An inquiry holds no inventory, so there is nothing for a channel to
        // know about yet.
        if (! $event->reservation->blocksInventory()) {
            return;
        }

        $this->mark($event->reservation);
    }

    public function handleModified(ReservationModified $event): void
    {
        $payload = $event->payload();

        // A guest adding a toddler does not change what any channel can sell.
        if (! ($payload['dates_changed'] ?? false) && ! ($payload['unit_changed'] ?? false)) {
            return;
        }

        $this->mark($event->reservation, $payload['previous'] ?? null);
    }

    public function handleCancelled(ReservationCancelled $event): void
    {
        // The most urgent case of all: dates that just became sellable again,
        // and every hour they stay closed on a channel is revenue lost.
        $this->mark($event->reservation);
    }

    /**
     * @param  array<string, mixed>|null  $previous  Dates before a change, so a
     *                                               moved booking marks both windows.
     */
    private function mark(object $reservation, ?array $previous = null): void
    {
        if ($reservation->listing_id === null) {
            return;
        }

        $organization = $reservation->organization;

        if ($organization === null) {
            return;
        }

        try {
            $this->tenancy->runAs($organization, function () use ($reservation, $previous): void {
                $from = $reservation->check_in_date->toDateString();
                $to = $reservation->check_out_date->toDateString();

                // A moved booking frees its old dates and takes new ones, so
                // both windows need pushing. Marking only the new ones leaves
                // the old dates closed on every channel.
                if (isset($previous['check_in_date'], $previous['check_out_date'])) {
                    $from = min($from, (string) $previous['check_in_date']);
                    $to = max($to, (string) $previous['check_out_date']);
                }

                $this->sync->markListingDirty($reservation->listing_id, 'availability', $from, $to);
            });
        } catch (\Throwable $exception) {
            // Channel bookkeeping must never be able to fail a booking. The
            // scheduler's full-horizon sweep is the safety net.
            Log::error('Could not mark channels dirty for a reservation.', [
                'reservation_id' => $reservation->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
