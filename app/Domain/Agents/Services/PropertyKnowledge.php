<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use Carbon\CarbonImmutable;

/**
 * What an agent is allowed to know about a property, for one guest.
 *
 * This class is the security boundary of the whole feature, and it is worth
 * being blunt about why. A language model cannot be relied on to keep a secret
 * it has been shown. Instructing it to "never reveal the door code unless the
 * booking is paid" is a request, not a control: it can be talked out of that by
 * a guest who claims to be the cleaner, and it will be, because people try.
 *
 * So the door code is not in the prompt at all unless the guest is entitled to
 * it. The model cannot leak what it was never given. Everything here follows
 * from that:
 *
 *  - **Secrets are opt-in per reservation**, gated on the booking being real,
 *    paid, and inside its access window — the same conditions the access-code
 *    machinery uses, for the same reasons.
 *  - **What was withheld is reported**, not silently dropped, so the agent can
 *    say "I'll have someone send that over" instead of inventing a code, and so
 *    a person reading the draft can see why.
 *  - **Nothing is summarised on the way in.** The facts are the real column
 *    values. A paraphrase in this layer would be a second place for the truth
 *    to drift from the property record.
 */
class PropertyKnowledge
{
    /**
     * Facts safe to put in front of any guest who has written to us.
     *
     * @return array<string, mixed>
     */
    public function public(Property $property): array
    {
        $listing = $property->listings()->where('status', 'published')->first();

        return array_filter([
            'name' => $property->display_name ?? $property->name,
            'type' => $property->property_type_label ?? null,
            'city' => $property->city,
            'country' => $property->country_code,
            'timezone' => $property->timezone,
            'max_occupancy' => $property->max_occupancy,
            'bedrooms' => $property->bedrooms,
            'bathrooms' => $property->bathrooms,

            'check_in_from' => $property->check_in_time,
            'check_in_until' => $property->check_in_until,
            'check_out_by' => $property->check_out_time,
            'check_in_method' => $property->check_in_method,

            'house_rules' => $property->house_rules,
            // The address a guest may see. Not the same as the arrival
            // instructions, which can name a key safe.
            'address' => $this->address($property),

            'amenities' => $property->amenities()->pluck('name')->values()->all(),
            'description' => $listing?->description ?? $listing?->summary,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * Arrival details, which are secrets.
     *
     * Returned only when the guest in front of us is entitled to them. The
     * second element of the pair is why they were withheld, for the agent to
     * say out loud rather than guess around.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public function arrival(Property $property, ?Reservation $reservation): array
    {
        $withheld = $this->reasonsToWithhold($reservation);

        if ($withheld !== []) {
            return [[], $withheld];
        }

        return [array_filter([
            'check_in_instructions' => $property->check_in_instructions,
            'access_notes' => $property->access_notes,
            'door_code' => $property->door_code,
            'wifi_network' => $property->wifi_network,
            'wifi_password' => $property->wifi_password,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''), []];
    }

    /**
     * Why this guest may not be told how to get in.
     *
     * Each reason is phrased for a guest to read, because the agent repeats it.
     *
     * @return list<string>
     */
    public function reasonsToWithhold(?Reservation $reservation): array
    {
        if ($reservation === null) {
            return ['There is no booking attached to this conversation.'];
        }

        $reasons = [];

        $status = $reservation->status instanceof ReservationStatus
            ? $reservation->status
            : ReservationStatus::from((string) $reservation->status);

        if (! in_array($status, [ReservationStatus::Confirmed, ReservationStatus::CheckedIn], true)) {
            $reasons[] = sprintf('The booking is %s rather than confirmed.', $status->value);
        }

        // Unpaid is the case people actually try. A guest who has not paid
        // asking for the door code is either confused or not a guest.
        if ((int) $reservation->balance_due > 0) {
            $reasons[] = 'There is a balance outstanding on the booking.';
        }

        // Arrival and departure are date columns: they carry no time of day, and
        // reading them as instants is wrong by the property's UTC offset. For a
        // Lisbon property that mattered in exactly the wrong direction — midnight
        // local is 23:00 UTC the previous day, so a guest arriving tomorrow read
        // as arriving too early and was refused the door code all day. Shifting
        // the zone rather than converting keeps the wall clock, which is what a
        // date means, and puts both sides of every comparison in the same day.
        $timezone = $reservation->property?->timezone ?? 'UTC';
        $today = CarbonImmutable::today($timezone);
        $checkIn = $reservation->check_in_date === null
            ? null
            : CarbonImmutable::parse($reservation->check_in_date)->shiftTimezone($timezone)->startOfDay();
        $checkOut = $reservation->check_out_date === null
            ? null
            : CarbonImmutable::parse($reservation->check_out_date)->shiftTimezone($timezone)->startOfDay();

        // A code handed out three weeks early is a code that has been passed
        // on, written down, or photographed by then.
        if ($checkIn !== null && $today->lessThan($checkIn->subDay())) {
            $reasons[] = sprintf('Arrival is %s; details are shared the day before.', $checkIn->toDateString());
        }

        if ($checkOut !== null && $today->greaterThan($checkOut)) {
            $reasons[] = 'The stay has ended.';
        }

        return $reasons;
    }

    /**
     * What the agent needs to know about this particular stay.
     *
     * @return array<string, mixed>
     */
    public function stay(?Reservation $reservation): array
    {
        if ($reservation === null) {
            return [];
        }

        $status = $reservation->status instanceof ReservationStatus
            ? $reservation->status->value
            : (string) $reservation->status;

        return array_filter([
            'confirmation_code' => $reservation->confirmation_code,
            'status' => $status,
            'check_in_date' => $reservation->check_in_date?->toDateString(),
            'check_out_date' => $reservation->check_out_date?->toDateString(),
            'nights' => $reservation->nights,
            'adults' => $reservation->adults,
            'children' => $reservation->children,
            'balance_due_is_zero' => (int) $reservation->balance_due === 0,
            'source' => $reservation->source,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function address(Property $property): ?string
    {
        $parts = array_filter([
            $property->address_line_1,
            $property->address_line_2,
            $property->city,
            $property->postal_code,
        ], static fn (mixed $part): bool => $part !== null && $part !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }
}
