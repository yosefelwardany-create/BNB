<?php

declare(strict_types=1);

namespace App\Domain\Guests\Services;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConversationService;
use App\Domain\Payments\Models\PaymentSchedule;
use App\Domain\Reservations\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a guest can see and do about their own booking.
 *
 * Access is by an unguessable link rather than an account. Asking somebody to
 * create a password to look at a booking they have already paid for produces
 * support calls, not security — and the link only ever goes to the address
 * that made the booking.
 *
 * That makes the token the whole of the authorisation, so two things matter
 * more here than anywhere else in the platform:
 *
 * **It expires.** A confirmation email forwarded to a group chat two years
 * later must not still open the booking.
 *
 * **What it opens is narrow.** The portal returns a deliberately small view:
 * this stay, its balance, its messages. Not the guest's other bookings, not
 * the property's other guests, not anything the token holder could use to walk
 * sideways. A leaked link should expose one stay, not an account.
 */
class GuestPortalService
{
    public function __construct(private readonly ConversationService $conversations) {}

    /**
     * Issue or refresh a booking's portal link.
     *
     * Regenerating replaces the previous token immediately, which is how a
     * guest who forwarded their link to the wrong person gets it back.
     */
    public function issueToken(Reservation $reservation, bool $regenerate = false): string
    {
        if (! $regenerate && $reservation->portal_token !== null && ! $this->hasExpired($reservation)) {
            return $reservation->portal_token;
        }

        $bytes = (int) config('pms.guest_portal.token_bytes', 32);

        $reservation->forceFill([
            'portal_token' => Str::random(max(32, $bytes)),
            'portal_token_expires_at' => $this->expiryFor($reservation),
        ])->save();

        return $reservation->portal_token;
    }

    /**
     * The reservation a token opens, if it still opens one.
     *
     * Looked up without the tenant scope because a portal request arrives with
     * no organization context — the token itself identifies the tenant. It is
     * unique across the platform, so this cannot resolve ambiguously.
     */
    public function resolve(string $token): ?Reservation
    {
        $reservation = Reservation::query()
            ->withoutGlobalScope('organization')
            ->with(['property', 'listing', 'guest'])
            ->where('portal_token', $token)
            ->first();

        if ($reservation === null || $this->hasExpired($reservation)) {
            return null;
        }

        return $reservation;
    }

    /**
     * Everything the portal shows about a stay.
     *
     * Assembled explicitly rather than by serialising the reservation, because
     * the difference between the two is the internal notes, the channel
     * commission, the owner's identity and the cost of the clean — all of
     * which are on the record and none of which are the guest's business.
     *
     * @return array<string, mixed>
     */
    public function overview(Reservation $reservation): array
    {
        $property = $reservation->property;
        $balance = $reservation->balanceDue();

        return [
            'confirmation_code' => $reservation->confirmation_code,
            'status' => $reservation->status->value,
            'status_label' => $reservation->status->label(),

            'check_in_date' => $reservation->check_in_date?->toDateString(),
            'check_out_date' => $reservation->check_out_date?->toDateString(),
            'nights' => (int) $reservation->nights,
            'adults' => (int) $reservation->adults,
            'children' => (int) $reservation->children,
            'infants' => (int) $reservation->infants,
            'pets' => (int) $reservation->pets,

            'property' => [
                'name' => $property?->name,
                // The full address only once the stay is paid for and close
                // enough to matter. Before that a city is enough, and handing
                // out an exact address for an unpaid booking is how a property
                // gets visited by somebody who never intended to stay.
                'address' => $this->shouldRevealAddress($reservation)
                    ? $this->address($property)
                    : null,
                'city' => $property?->city,
                'country_code' => $property?->country_code,
                'timezone' => $property?->timezone,
                'check_in_time' => $property?->check_in_time,
                'check_out_time' => $property?->check_out_time,
                'check_in_until' => $property?->check_in_until,
                'check_in_method' => $property?->check_in_method,
                'address_available_from' => $this->addressAvailableFrom($reservation)?->toDateString(),
            ],

            'currency' => $reservation->currency,
            'grand_total' => $reservation->grandTotal()->jsonSerialize(),
            'paid_total' => $reservation->paidTotal()->jsonSerialize(),
            'balance_due' => $balance->jsonSerialize(),
            'is_paid_in_full' => ! $balance->isPositive(),

            'guest_notes' => $reservation->guest_notes,

            'online_check_in' => [
                'completed' => $reservation->online_check_in_completed_at !== null,
                'completed_at' => $reservation->online_check_in_completed_at?->toIso8601String(),
                'estimated_arrival_time' => $reservation->estimated_arrival_time,
                'estimated_departure_time' => $reservation->estimated_departure_time,
            ],

            // What the guest actually agreed to, from the snapshot taken at
            // booking — not from the policy as it stands today.
            'cancellation_policy' => $reservation->cancellation_policy_snapshot,
        ];
    }

    /**
     * The instalments still owed.
     *
     * @return list<array<string, mixed>>
     */
    public function schedule(Reservation $reservation): array
    {
        return PaymentSchedule::query()
            ->withoutGlobalScope('organization')
            ->where('reservation_id', $reservation->getKey())
            ->orderBy('sequence')
            ->get()
            ->map(fn (PaymentSchedule $s): array => [
                'id' => $s->getKey(),
                'label' => $s->label,
                'due_on' => $s->due_on?->toDateString(),
                'amount' => $s->amount()->jsonSerialize(),
                'paid_amount' => $s->paidAmount()->jsonSerialize(),
                'outstanding_amount' => $s->outstandingAmount()->jsonSerialize(),
                'status' => $s->status,
                'is_overdue' => $s->isOverdue(),
            ])
            ->all();
    }

    /**
     * Record what the guest told us at online check-in.
     *
     * Written to the reservation rather than merged into the guest profile.
     * A guest correcting a detail for this stay is not thereby correcting
     * their permanent record, and silently doing both would let one booking
     * rewrite another.
     *
     * @param  array<string, mixed>  $details
     */
    public function completeCheckIn(Reservation $reservation, array $details): Reservation
    {
        return DB::transaction(function () use ($reservation, $details): Reservation {
            $reservation->forceFill([
                'check_in_details' => array_merge($reservation->check_in_details ?? [], $details),
                'estimated_arrival_time' => $details['estimated_arrival_time']
                    ?? $reservation->estimated_arrival_time,
                'estimated_departure_time' => $details['estimated_departure_time']
                    ?? $reservation->estimated_departure_time,
                'online_check_in_completed_at' => now(),
            ])->save();

            return $reservation->fresh();
        });
    }

    /**
     * The conversation for this booking, creating it on first use.
     */
    public function conversation(Reservation $reservation): Conversation
    {
        return $this->conversations->forReservation($reservation);
    }

    /**
     * A message from the guest.
     *
     * Attributed to the guest rather than to staff, which is what puts it on
     * the correct side of the thread and stops it being counted as a reply in
     * response-time reporting.
     */
    public function sendMessage(Reservation $reservation, string $body): Message
    {
        return $this->conversations->recordInbound($this->conversation($reservation), [
            'body' => $body,
            'direction' => Message::INBOUND,
            'author_type' => 'guest',
            'transport' => 'portal',
        ]);
    }

    public function markViewed(Reservation $reservation): void
    {
        // Not a model save: this fires on every portal page load, and a full
        // save would raise events and rewrite every column to set a timestamp.
        Reservation::query()
            ->withoutGlobalScope('organization')
            ->whereKey($reservation->getKey())
            ->update(['portal_last_viewed_at' => now()]);
    }

    public function hasExpired(Reservation $reservation): bool
    {
        return $reservation->portal_token_expires_at !== null
            && $reservation->portal_token_expires_at->isPast();
    }

    /**
     * How long a link lives: until well after the guest has gone.
     *
     * Long enough that somebody can find their receipt the week after they
     * get home, short enough that a forwarded confirmation email is not a
     * permanent key to somebody's holiday.
     */
    private function expiryFor(Reservation $reservation): CarbonImmutable
    {
        $days = (int) config('pms.guest_portal.valid_days_after_checkout', 14);

        return CarbonImmutable::parse($reservation->check_out_date)->addDays($days)->endOfDay();
    }

    /**
     * Whether the exact address may be shown yet.
     *
     * Paid for, and close enough to arrival to need it. Both conditions
     * matter: an unpaid booking is not yet a guest, and an address handed out
     * months early is an address handed to whoever the link reaches.
     */
    private function shouldRevealAddress(Reservation $reservation): bool
    {
        if (! $reservation->status->blocksInventory()) {
            return false;
        }

        if ($reservation->balanceDue()->isPositive()) {
            return false;
        }

        $from = $this->addressAvailableFrom($reservation);

        return $from === null || ! CarbonImmutable::today()->lessThan($from);
    }

    private function addressAvailableFrom(Reservation $reservation): ?CarbonImmutable
    {
        $days = (int) config('pms.guest_portal.address_days_before_arrival', 7);

        return CarbonImmutable::parse($reservation->check_in_date)->subDays($days);
    }

    /**
     * @return array<string, mixed>
     */
    private function address(?object $property): array
    {
        if ($property === null) {
            return [];
        }

        return array_filter([
            'line_1' => $property->address_line_1,
            'line_2' => $property->address_line_2,
            'city' => $property->city,
            'state' => $property->state,
            'postal_code' => $property->postal_code,
            'country_code' => $property->country_code,
            'latitude' => $property->latitude,
            'longitude' => $property->longitude,
            // The instructions travel with the address, and for the same
            // reason: they are what a guest needs to get in, and what nobody
            // else should be given.
            'check_in_instructions' => $property->check_in_instructions,
            'check_out_instructions' => $property->check_out_instructions,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
