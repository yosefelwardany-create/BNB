<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Domain\Guests\Services\GuestPortalService;
use App\Domain\Messaging\Models\Message;
use App\Domain\Organization\Models\Organization;
use App\Domain\Payments\Models\PaymentSchedule;
use App\Domain\Payments\Services\PaymentScheduleService;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The guest's view of their own booking.
 *
 * Unauthenticated in the ordinary sense: the link *is* the credential. That
 * shapes every method here.
 *
 * **The tenant comes from the token, never from a header.** A portal request
 * arrives with no organization context, and letting the caller supply one
 * would be handing them a dial to point at somebody else's data.
 *
 * **Every response is assembled explicitly.** Returning a serialised
 * reservation would leak internal notes, channel commission, the owner's
 * identity and the cost of the clean. The portal returns a narrow, purpose-
 * built view, so a leaked link exposes one stay rather than an account.
 *
 * **A bad token is indistinguishable from an expired one.** Both return the
 * same 404, because telling somebody a token once existed is telling them
 * something.
 *
 * This controller extends the base routing controller rather than the API one:
 * it has no authenticated user, no permissions and no ambient tenant, so the
 * helpers on the API controller would all be wrong here.
 */
class GuestPortalController extends Controller
{
    public function __construct(
        private readonly GuestPortalService $portal,
        private readonly PaymentScheduleService $schedules,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * The stay.
     */
    public function show(string $token): JsonResponse
    {
        $reservation = $this->reservation($token);

        $this->portal->markViewed($reservation);

        return $this->inTenant($reservation, fn (): JsonResponse => response()->json([
            'data' => $this->portal->overview($reservation),
        ]));
    }

    /**
     * What is owed, and when.
     */
    public function payments(string $token): JsonResponse
    {
        $reservation = $this->reservation($token);

        return $this->inTenant($reservation, fn (): JsonResponse => response()->json([
            'data' => [
                'currency' => $reservation->currency,
                'grand_total' => $reservation->grandTotal()->jsonSerialize(),
                'paid_total' => $reservation->paidTotal()->jsonSerialize(),
                'balance_due' => $reservation->balanceDue()->jsonSerialize(),
                'schedule' => $this->portal->schedule($reservation),
            ],
        ]));
    }

    /**
     * Pay an instalment.
     *
     * The guest names an instalment rather than an amount. Letting them send
     * an arbitrary figure would mean trusting a number from outside the system
     * about how much is owed, and the schedule already knows.
     */
    public function pay(Request $request, string $token): JsonResponse
    {
        $reservation = $this->reservation($token);

        $data = $request->validate([
            'payment_schedule_id' => ['required', 'string', 'size:26'],
        ]);

        return $this->inTenant($reservation, function () use ($reservation, $data): JsonResponse {
            $schedule = PaymentSchedule::query()
                ->where('reservation_id', $reservation->getKey())
                ->whereKey($data['payment_schedule_id'])
                ->first();

            // Scoped to this reservation, so an instalment id from another
            // booking is simply not found rather than chargeable.
            abort_if($schedule === null, 404, 'That instalment does not belong to this booking.');

            $payment = $this->schedules->charge($schedule);

            return response()->json([
                'data' => [
                    'paid' => $payment->capturedAmount()->jsonSerialize(),
                    'balance_due' => $reservation->fresh()->balanceDue()->jsonSerialize(),
                    // Said plainly, because a guest whose "payment" went
                    // through a local simulation deserves to know it did.
                    'is_simulated' => (bool) $payment->is_simulated,
                ],
            ]);
        });
    }

    /**
     * The conversation with the host.
     */
    public function messages(string $token): JsonResponse
    {
        $reservation = $this->reservation($token);

        return $this->inTenant($reservation, function () use ($reservation): JsonResponse {
            $conversation = $this->portal->conversation($reservation);

            $messages = $conversation->messages()
                ->latest()
                ->limit(100)
                ->get()
                ->reverse()
                ->values()
                // Deliberately not the message resource: that carries internal
                // notes, the author's identity and delivery metadata, none of
                // which belong in front of a guest.
                ->map(fn (Message $message): array => [
                    'id' => $message->getKey(),
                    'body' => $message->body,
                    'from_guest' => $message->direction === Message::INBOUND,
                    'sent_at' => $message->created_at?->toIso8601String(),
                ]);

            return response()->json(['data' => $messages]);
        });
    }

    /**
     * Send a message to the host.
     */
    public function sendMessage(Request $request, string $token): JsonResponse
    {
        $reservation = $this->reservation($token);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        return $this->inTenant($reservation, function () use ($reservation, $data): JsonResponse {
            $message = $this->portal->sendMessage($reservation, $data['body']);

            return response()->json([
                'data' => [
                    'id' => $message->getKey(),
                    'body' => $message->body,
                    'from_guest' => true,
                    'sent_at' => $message->created_at?->toIso8601String(),
                ],
            ], 201);
        });
    }

    /**
     * Online check-in.
     */
    public function checkIn(Request $request, string $token): JsonResponse
    {
        $reservation = $this->reservation($token);

        $data = $request->validate([
            'estimated_arrival_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'estimated_departure_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'guests' => ['sometimes', 'array', 'max:50'],
            'guests.*.first_name' => ['required', 'string', 'max:120'],
            'guests.*.last_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'guests.*.date_of_birth' => ['sometimes', 'nullable', 'date'],
            'guests.*.nationality' => ['sometimes', 'nullable', 'string', 'size:2'],
            'guests.*.document_type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'guests.*.document_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'purpose_of_stay' => ['sometimes', 'nullable', 'string', 'max:64'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return $this->inTenant($reservation, function () use ($reservation, $data): JsonResponse {
            $updated = $this->portal->completeCheckIn($reservation, $data);

            return response()->json([
                'data' => $this->portal->overview($updated),
            ]);
        });
    }

    /**
     * The booking a token opens.
     *
     * A missing token, an expired one and a revoked one all produce the same
     * 404. Distinguishing them would confirm to somebody probing links that a
     * particular one was once real.
     */
    private function reservation(string $token): Reservation
    {
        $reservation = $this->portal->resolve($token);

        abort_if($reservation === null, 404, 'This booking link is not valid, or has expired.');

        return $reservation;
    }

    /**
     * Run inside the booking's own tenant.
     *
     * Everything the portal touches — messages, payments, schedules — is
     * tenant-scoped, and a portal request has no ambient tenant. Binding it
     * from the reservation rather than from the request is what stops a token
     * holder reaching another organization's data.
     */
    private function inTenant(Reservation $reservation, \Closure $callback): JsonResponse
    {
        $organization = $this->tenancy->withoutScope(
            fn () => Organization::query()
                ->find($reservation->organization_id),
        );

        abort_if($organization === null, 404, 'This booking link is not valid, or has expired.');

        return $this->tenancy->runAs($organization, $callback);
    }
}
