<?php

declare(strict_types=1);

namespace App\Domain\Reservations\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Reservations\Models\Reservation;
use Illuminate\Database\Eloquent\Model;

/**
 * A reservation exists. Raised for every status, including inquiries, so the
 * inbox and CRM see enquiries too.
 */
class ReservationCreated extends AbstractDomainEvent
{
    public const NAME = 'reservation.created';

    public function __construct(public readonly Reservation $reservation)
    {
        parent::__construct();
    }

    public function subject(): ?Model
    {
        return $this->reservation;
    }

    public function idempotencyKey(): ?string
    {
        // A channel redelivering the same booking must not raise this twice.
        return $this->reservation->external_reservation_id === null
            ? null
            : sprintf('reservation.created:%s:%s', $this->reservation->source, $this->reservation->external_reservation_id);
    }

    public function payload(): array
    {
        return ReservationPayload::build($this->reservation);
    }
}
