<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyDocument;
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
            // The enum's own label, not `property_type_label` — that is a field
            // the API resource composes, not an attribute on the model. Reading
            // it here returned null in production and raised a missing-attribute
            // error everywhere else, so the agent was either told nothing about
            // what kind of place this is, or it was a 500.
            'type' => $property->property_type?->label(),
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
            'description' => $property->description ?? $listing?->description ?? $listing?->summary,

            /*
             * The house manual, where somebody has said a guest may see it.
             *
             * Opt in, and off by default, for the reason the rest of this class
             * exists: a house manual routinely contains a door code, and a
             * document pasted wholesale into a guest's prompt would walk around
             * the entitlement gate rather than through it. Marking one
             * guest-safe is a decision an operator makes about a document they
             * have read.
             */
            'knowledge' => $this->documents($property, guestSafeOnly: true),
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * What this property's own documents say.
     *
     * Dated, because they are snapshots of a document maintained somewhere else.
     * An agent quoting a check-in time that changed last Tuesday is worse than
     * one that says it is working from a copy taken on a date — the second can
     * be checked.
     *
     * @return array<string, mixed>
     */
    public function documents(Property $property, bool $guestSafeOnly): array
    {
        $documents = $property->documents()
            ->where('status', PropertyDocument::STATUS_OK)
            ->when($guestSafeOnly, fn ($query) => $query->where('is_guest_safe', true))
            ->get();

        $readable = [];

        foreach ($documents as $document) {
            if (! $document->isUsable()) {
                continue;
            }

            $readable[] = array_filter([
                'document' => $document->label(),
                'as_at' => $document->fetched_at?->toDateString(),
                // Said out loud, so an answer can be hedged rather than
                // confidently drawn from a document that stops mid-sentence.
                'is_partial' => $document->was_truncated ?: null,
                'text' => $document->content,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $readable;
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
        if ($reservation->source === 'hostex' && $reservation->balance_due === null) {
            $reasons[] = 'Payment has not been independently verified for this channel booking.';
        }
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
            'confirmation_code' => $reservation->external_confirmation_code ?? $reservation->external_reservation_id ?? $reservation->confirmation_code,
            'internal_reference' => $reservation->confirmation_code,
            'guest_name' => $reservation->guest?->display_name,
            'status' => $status,
            'check_in_date' => $reservation->check_in_date?->toDateString(),
            'check_out_date' => $reservation->check_out_date?->toDateString(),
            'nights' => $reservation->nights,
            ...$reservation->guestCounts(),
            'guest_notes' => $reservation->guest_notes,
            'balance_due_is_zero' => $reservation->balance_due === null ? null : (int) $reservation->balance_due === 0,
            'payment_verified' => $reservation->balance_due !== null,
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
