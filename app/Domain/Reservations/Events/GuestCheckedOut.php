<?php

declare(strict_types=1);

namespace App\Domain\Reservations\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Reservations\Models\Reservation;
use Illuminate\Database\Eloquent\Model;

/**
 * The guest has left. Cleaning, inspection and review-request workflows all
 * hang off this.
 */
class GuestCheckedOut extends AbstractDomainEvent
{
    public const NAME = 'reservation.checked_out';

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
        return ReservationPayload::build($this->reservation) + [
            'checked_out_at' => $this->reservation->checked_out_at?->toIso8601String(),
        ];
    }
}
