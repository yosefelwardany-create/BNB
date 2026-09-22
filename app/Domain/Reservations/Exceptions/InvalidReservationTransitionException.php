<?php

declare(strict_types=1);

namespace App\Domain\Reservations\Exceptions;

use App\Domain\Reservations\Enums\ReservationStatus;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Raised when a reservation is asked to move to a state it cannot reach.
 *
 * The message names what is actually possible, because the common cause is a
 * user or an integration acting on stale information — the booking was already
 * cancelled, or already checked out.
 */
class InvalidReservationTransitionException extends HttpException
{
    public function __construct(
        public readonly ReservationStatus $from,
        public readonly ReservationStatus $to,
    ) {
        $allowed = array_map(
            fn (ReservationStatus $s): string => $s->label(),
            $from->allowedTransitions(),
        );

        parent::__construct(422, sprintf(
            'A %s reservation cannot become %s.%s',
            $from->label(),
            $to->label(),
            $allowed === []
                ? ' It is in a final state.'
                : ' It can become: '.implode(', ', $allowed).'.',
        ));
    }
}
