<?php

declare(strict_types=1);

namespace App\Domain\Reservations\Events;

use App\Domain\Reservations\Models\Reservation;

/**
 * The shape reservation events publish.
 *
 * Shared by every reservation event so automation conditions, webhook
 * subscribers and analytics all see the same field names whatever happened.
 * Contains no models and no payment details.
 */
final class ReservationPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Reservation $reservation): array
    {
        return [
            'reservation_id' => $reservation->getKey(),
            'confirmation_code' => $reservation->confirmation_code,
            'status' => $reservation->status->value,
            'source' => $reservation->source,

            'property_id' => $reservation->property_id,
            'listing_id' => $reservation->listing_id,
            'unit_id' => $reservation->unit_id,
            'guest_id' => $reservation->guest_id,

            'check_in_date' => $reservation->check_in_date->toDateString(),
            'check_out_date' => $reservation->check_out_date->toDateString(),
            'nights' => (int) $reservation->nights,
            'adults' => (int) $reservation->adults,
            'children' => (int) $reservation->children,
            'infants' => (int) $reservation->infants,
            'pets' => (int) $reservation->pets,

            'currency' => $reservation->currency,
            'accommodation_total' => (int) $reservation->accommodation_total,
            'fees_total' => (int) $reservation->fees_total,
            'taxes_total' => (int) $reservation->taxes_total,
            'discounts_total' => (int) $reservation->discounts_total,
            'grand_total' => (int) $reservation->grand_total,
            'balance_due' => (int) $reservation->balance_due,
            'paid_total' => (int) $reservation->paid_total,

            'booked_at' => $reservation->booked_at?->toIso8601String(),
            'days_until_arrival' => $reservation->daysUntilArrival(),
        ];
    }
}
