<?php

declare(strict_types=1);

namespace App\Domain\Reservations\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * The booking is off.
 *
 * The refund amount is carried but *not* yet moved: the payment service
 * listens for this and issues the refund. Separating the decision from the
 * transfer means a payment provider outage cannot leave a reservation half
 * cancelled.
 */
class ReservationCancelled extends AbstractDomainEvent
{
    public const NAME = 'reservation.cancelled';

    public function __construct(
        public readonly Reservation $reservation,
        public readonly Money $refundDue,
        public readonly ?string $reason = null,
        public readonly string $cancelledBy = 'host',
        public readonly ?string $explanation = null,
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
            'reason' => $this->reason,
            'cancelled_by' => $this->cancelledBy,
            'refund_due' => $this->refundDue->minorUnits,
            'refund_currency' => $this->refundDue->currency,
            'refund_explanation' => $this->explanation,
        ];
    }
}
