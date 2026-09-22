<?php

declare(strict_types=1);

namespace App\Domain\Reservations\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Reservations\Models\Reservation;
use Illuminate\Database\Eloquent\Model;

/**
 * Dates, unit or guest count changed.
 *
 * Carries the previous values so consumers can decide what to do: a date
 * change needs the cleaning rescheduled and the channels updated, a guest
 * count change usually does not.
 */
class ReservationModified extends AbstractDomainEvent
{
    public const NAME = 'reservation.modified';

    /**
     * @param  array<string, mixed>  $previous
     */
    public function __construct(
        public readonly Reservation $reservation,
        public readonly array $previous = [],
        public readonly ?string $reason = null,
    ) {
        parent::__construct();
    }

    public function subject(): ?Model
    {
        return $this->reservation;
    }

    public function payload(): array
    {
        return ReservationPayload::build($this->reservation) + [
            'previous' => $this->previous,
            'reason' => $this->reason,
            'dates_changed' => ($this->previous['check_in_date'] ?? null) !== $this->reservation->check_in_date->toDateString()
                || ($this->previous['check_out_date'] ?? null) !== $this->reservation->check_out_date->toDateString(),
            'unit_changed' => ($this->previous['unit_id'] ?? null) !== $this->reservation->unit_id,
        ];
    }
}
