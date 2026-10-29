<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reservations;

use App\Domain\Integrations\Exceptions\StripeRequestException;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Websites\Exceptions\CheckoutRefused;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Domain\Websites\Services\WebsitePayments;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The host's answer to a request to book made on a website.
 *
 * Accepting takes the money the guest's card was authorised for; declining
 * lets it go and reopens the nights.
 */
class WebsiteRequestController extends Controller
{
    public function __construct(private readonly WebsitePayments $payments) {}

    public function accept(Reservation $reservation): ReservationResource|JsonResponse
    {
        $this->authorize('update', $reservation);

        try {
            $this->payments->accept($this->checkout($reservation));
        } catch (CheckoutRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], $refused->status);
        } catch (StripeRequestException) {
            return $this->stripeUnreachable();
        }

        return $this->fresh($reservation);
    }

    public function decline(Request $request, Reservation $reservation): ReservationResource|JsonResponse
    {
        $this->authorize('update', $reservation);

        // As long as the booking's cancellation reason may be.
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $this->payments->decline($this->checkout($reservation), $validated['reason'] ?? null);
        } catch (CheckoutRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], $refused->status);
        } catch (StripeRequestException) {
            return $this->stripeUnreachable();
        }

        return $this->fresh($reservation);
    }

    /**
     * The booking's website checkout, in the booking's own account. A booking
     * not made on a website has no request to answer.
     */
    private function checkout(Reservation $reservation): WebsiteCheckout
    {
        $checkout = WebsiteCheckout::query()
            ->where('reservation_id', $reservation->getKey())
            ->where('organization_id', $reservation->organization_id)
            ->latest('id')
            ->first();

        abort_if($checkout === null, 404);

        return $checkout;
    }

    /**
     * Stripe did not answer, or answered with something other than a card's
     * refusal. The answer is rolled back whole, so it can simply be given
     * again.
     */
    private function stripeUnreachable(): JsonResponse
    {
        return response()->json(['message' => 'Stripe could not be reached. Nothing has changed; try again in a minute.'], 503);
    }

    private function fresh(Reservation $reservation): ReservationResource
    {
        return new ReservationResource($reservation->fresh()->load(['property', 'guest', 'websiteCheckout']));
    }
}
