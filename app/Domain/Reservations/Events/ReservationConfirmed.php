<?php

declare(strict_types=1);

namespace App\Domain\Reservations\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Reservations\Models\Reservation;
use Illuminate\Database\Eloquent\Model;

/**
 * The booking is firm.
 *
 * This is the busiest event in the product: it drives the payment schedule,
 * the guest confirmation, the cleaning task, the channel availability push,
 * owner accounting and the analytics feed. Each of those is an independent
 * listener on the event rather than a call inside the reservation service,
 * which is what keeps the service from accumulating every downstream concern.
 */
class ReservationConfirmed extends AbstractDomainEvent
{
    public const NAME = 'reservation.confirmed';

    public function __construct(public readonly Reservation $reservation)
    {
        parent::__construct();
    }

    public function subject(): ?Model
    {
        return $this->reservation;
    }

    public function payload(): array
    {
        return ReservationPayload::build($this->reservation);
    }
}
