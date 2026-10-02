<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Integrations\Support\HostexData;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Money\Money;

/** Shared API and agent representation, with explicit financial provenance. */
final class HostexReservationView
{
    public static function for(Reservation $reservation): array
    {
        $source = $reservation->source_metadata['hostex'] ?? [];
        $financials = $source['financials'] ?? [];
        $accommodation = HostexData::detailTotal($financials, 'ACCOMMODATION');
        $average = isset($accommodation['amount'], $accommodation['currency'])
            ? Money::of($accommodation['amount'], $accommodation['currency'])->multiply(1 / max(1, (int) $reservation->nights))->jsonSerialize() : null;

        return [
            'reservation_code' => $source['reservation_code'] ?? $reservation->hostex_reservation_code,
            'stay_code' => $reservation->external_reservation_id,
            'channel_type' => $source['channel_type'] ?? null,
            'channel_id' => $reservation->external_confirmation_code,
            'number_of_guests' => $source['number_of_guests'] ?? null,
            'guest_details' => $source['guest_details'] ?? [],
            'guest_notes' => $reservation->guest_notes,
            'synced_at' => $source['synced_at'] ?? null,
            'limitations' => $source['limitations'] ?? ['Run a full Pull to repair this legacy import.'],
            'financials' => [
                'accommodation' => $accommodation,
                'cleaning_fee' => HostexData::detailTotal($financials, 'CLEANING_FEE'),
                'reservation_rate' => $financials['rate'] ?? null,
                'order_rate' => $financials['total_rate'] ?? null,
                'commission' => $financials['commission'] ?? null,
                'order_commission' => $financials['total_commission'] ?? null,
                'tax' => $financials['tax'] ?? null,
                'refund_detail' => HostexData::detailTotal($financials, 'CANCELLATION_REFUND_FROM_HOST'),
                'average_nightly_accommodation' => $average,
                'guest_total' => null, 'host_payout' => null, 'payout_status' => null,
                'payment' => $financials['payment'] ?? null,
                'details' => $financials['details'] ?? [],
                'additional_fees' => $financials['additional_fees'] ?? [],
            ],
        ];
    }
}
