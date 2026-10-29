<?php

declare(strict_types=1);

namespace App\Domain\Websites\Listeners;

use App\Domain\Reservations\Events\ReservationCancelled;
use App\Domain\Reservations\Events\ReservationConfirmed;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Websites\Jobs\AnswerWebsiteRequest;
use App\Domain\Websites\Models\WebsiteCheckout;

/**
 * A booking made by a website request and then confirmed or cancelled in
 * Habitat — not with the request's own Accept or Decline — answers the
 * request the same way: the guest's authorised money is taken, or let go,
 * and the guest is told.
 *
 * Without this a cancelled request kept the guest's money held until its
 * deadline, and a confirmed one was declined at it.
 */
class AnswerRequestWhenStaffDecide
{
    public function handleConfirmed(ReservationConfirmed $event): void
    {
        $this->answer($event->reservation, AnswerWebsiteRequest::ACCEPT);
    }

    public function handleCancelled(ReservationCancelled $event): void
    {
        $this->answer($event->reservation, AnswerWebsiteRequest::DECLINE, $event->reason, $event->cancelledBy);
    }

    private function answer(Reservation $reservation, string $answer, ?string $reason = null, string $answeredBy = 'host'): void
    {
        if ($reservation->website_id === null) {
            return;
        }

        $checkout = WebsiteCheckout::query()
            ->where('reservation_id', $reservation->getKey())
            ->where('organization_id', $reservation->organization_id)
            ->where('status', WebsiteCheckout::STATUS_REQUESTED)
            ->first();

        if ($checkout !== null) {
            AnswerWebsiteRequest::dispatch($checkout->getKey(), $answer, $reason, $answeredBy);
        }
    }
}
