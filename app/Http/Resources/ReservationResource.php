<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Reservations\Models\ReservationCharge;
use App\Domain\Reservations\Models\ReservationNight;
use App\Domain\Reservations\Models\ReservationStatusChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property \App\Domain\Reservations\Models\Reservation $resource
 */
class ReservationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reservation = $this->resource;

        return [
            'id' => $reservation->getKey(),
            'confirmation_code' => $reservation->confirmation_code,
            'status' => $reservation->status->value,
            'status_label' => $reservation->status->label(),
            'status_colour' => $reservation->status->colour(),
            'blocks_inventory' => $reservation->blocksInventory(),

            'source' => $reservation->source,
            'external_reservation_id' => $reservation->external_reservation_id,
            'external_confirmation_code' => $reservation->external_confirmation_code,

            'property_id' => $reservation->property_id,
            'listing_id' => $reservation->listing_id,
            'unit_id' => $reservation->unit_id,
            'unit_type_id' => $reservation->unit_type_id,
            'guest_id' => $reservation->guest_id,

            'stay' => [
                'check_in_date' => $reservation->check_in_date->toDateString(),
                'check_out_date' => $reservation->check_out_date->toDateString(),
                'nights' => (int) $reservation->nights,
                'check_in_time' => $this->timeString($reservation->check_in_time),
                'check_out_time' => $this->timeString($reservation->check_out_time),
                'days_until_arrival' => $reservation->daysUntilArrival(),
            ],

            'guests' => [
                'adults' => (int) $reservation->adults,
                'children' => (int) $reservation->children,
                'infants' => (int) $reservation->infants,
                'pets' => (int) $reservation->pets,
                'total' => $reservation->totalGuests(),
            ],

            'currency' => $reservation->currency,
            'financials' => [
                'accommodation' => $reservation->accommodationTotal()->jsonSerialize(),
                'fees' => $reservation->feesTotal()->jsonSerialize(),
                'taxes' => $reservation->taxesTotal()->jsonSerialize(),
                'discounts' => $reservation->discountsTotal()->jsonSerialize(),
                'grand_total' => $reservation->grandTotal()->jsonSerialize(),
                'paid' => $reservation->paidTotal()->jsonSerialize(),
                'refunded' => $reservation->refundedTotal()->jsonSerialize(),
                'balance_due' => $reservation->balanceDue()->jsonSerialize(),
                'channel_commission' => (int) $reservation->channel_commission,
                'expected_payout' => (int) $reservation->expected_payout,
                'average_daily_rate' => $reservation->averageDailyRate()->jsonSerialize(),
            ],

            'cancellation' => [
                'policy_id' => $reservation->cancellation_policy_id,
                'policy' => $reservation->cancellation_policy_snapshot,
                'cancelled_at' => $reservation->cancelled_at?->toIso8601String(),
                'cancelled_by' => $reservation->cancelled_by,
                'reason' => $reservation->cancellation_reason,
                'refund' => (int) $reservation->cancellation_refund,
            ],

            'guest_notes' => $reservation->guest_notes,
            'internal_notes' => $this->when(
                $request->user()?->can('reservations.update') ?? false,
                $reservation->internal_notes,
            ),

            'booked_at' => $reservation->booked_at?->toIso8601String(),
            'confirmed_at' => $reservation->confirmed_at?->toIso8601String(),
            'checked_in_at' => $reservation->checked_in_at?->toIso8601String(),
            'checked_out_at' => $reservation->checked_out_at?->toIso8601String(),
            'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
            'booking_lead_time_days' => $reservation->bookingLeadTimeDays(),

            'property' => new PropertyResource($this->whenLoaded('property')),
            'guest' => new GuestResource($this->whenLoaded('guest')),
            'unit' => new UnitResource($this->whenLoaded('unit')),

            'nights_breakdown' => $this->whenLoaded('stayNights', fn () => $reservation->stayNights
                ->map(fn (ReservationNight $night): array => [
                    'date' => $night->stay_date->toDateString(),
                    'rate' => $night->rate()->jsonSerialize(),
                    'unit_id' => $night->unit_id,
                    'recognised' => $night->recognised_at !== null,
                    // The full trace is available on request; the list view
                    // only needs a one-line explanation.
                    'pricing_trace' => $night->pricing_trace,
                ])->all()),

            'charges' => $this->whenLoaded('charges', fn () => $reservation->charges
                ->map(fn (ReservationCharge $charge): array => [
                    'id' => $charge->getKey(),
                    'kind' => $charge->kind,
                    'code' => $charge->code,
                    'label' => $charge->label,
                    'description' => $charge->description,
                    'quantity' => (int) $charge->quantity,
                    'amount' => $charge->amount()->jsonSerialize(),
                    'is_taxable' => $charge->is_taxable,
                    'is_refundable' => $charge->is_refundable,
                    'origin' => $charge->origin,
                    'calculation' => $charge->calculation_trace,
                ])->all()),

            'status_history' => $this->whenLoaded('statusChanges', fn () => $reservation->statusChanges
                ->map(fn (ReservationStatusChange $change): array => [
                    'from' => $change->from_status,
                    'to' => $change->to_status,
                    'reason' => $change->reason,
                    'actor_type' => $change->actor_type,
                    'by' => $change->relationLoaded('user') ? $change->user?->fullName() : null,
                    'at' => $change->created_at?->toIso8601String(),
                ])->all()),

            'created_at' => $reservation->created_at?->toIso8601String(),
            'updated_at' => $reservation->updated_at?->toIso8601String(),
        ];
    }

    private function timeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('H:i')
            : substr((string) $value, 0, 5);
    }
}
