<?php

declare(strict_types=1);

namespace App\Domain\Reservations\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Reservations\Models\Reservation;
use Illuminate\Database\Eloquent\Model;

class GuestCheckedIn extends AbstractDomainEvent
{
    public const NAME = 'reservation.checked_in';

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
            'checked_in_at' => $this->reservation->checked_in_at?->toIso8601String(),
        ];
    }
}
