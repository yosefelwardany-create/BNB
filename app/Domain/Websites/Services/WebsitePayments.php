<?php

declare(strict_types=1);

namespace App\Domain\Websites\Services;

use App\Domain\Availability\Exceptions\DatesUnavailableException;
use App\Domain\Integrations\DataObjects\WebhookEnvelope;
use App\Domain\Integrations\Exceptions\StripeRequestException;
use App\Domain\Integrations\Support\StripeClient;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentSchedule;
use App\Domain\Payments\Services\PaymentScheduleService;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Paying for a website hold with Stripe.
 *
 * The guest pays in their own browser, in Stripe's payment form; card details
 * never reach Habitat. What the browser says afterwards is only shown to the
 * guest. Habitat acts on Stripe's signed webhook, or on asking Stripe itself
 * when a hold runs out before the webhook has arrived.
 *
 * Instant book takes the money at once and confirms the booking. A request
 * to book only authorises the card; the host's request window starts then,
 * and accepting captures it (stage 4).
 */
class WebsitePayments
{
    /**
     * How long past its deadline a hold is kept while Stripe cannot be asked
     * whether it was paid. After that the nights are given back regardless;
     * a payment that arrives later is still honoured or refunded.
     */
    private const STRIPE_GRACE_MINUTES = 60;

    public function __construct(
        private readonly WebsiteCheckoutService $checkouts,
        private readonly ReservationService $reservations,
        private readonly PaymentService $payments,
        private readonly PaymentScheduleService $schedules,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Start Stripe's side of a checkout: a customer for the guest and a
     * PaymentIntent for what is due now. Returns the client secret the page
     * mounts Stripe's payment form with.
     *
     * The card is kept for later (`setup_future_usage`), so the balance can be
     * taken when it falls due. A request to book only authorises it.
     *
     * @throws StripeRequestException
     */
    public function startPayment(WebsiteCheckout $checkout): string
    {
        $stripe = $this->stripe();
        $key = 'website-checkout:'.$checkout->getKey();

        $customer = $stripe->post('/v1/customers', array_filter([
            'name' => trim(($checkout->guest['first_name'] ?? '').' '.($checkout->guest['last_name'] ?? '')),
            'email' => $checkout->guest['email'] ?? null,
            'phone' => $checkout->guest['phone'] ?? null,
            'metadata' => ['website_checkout_id' => $checkout->getKey()],
        ]), $key.':customer');

        $reference = $this->tenancy->withoutScope(fn (): ?string => Reservation::query()->whereKey($checkout->reservation_id)->value('confirmation_code'));

        $intent = $stripe->post('/v1/payment_intents', [
            'amount' => $checkout->due_now_amount,
            'currency' => strtolower($checkout->currency),
            'customer' => $customer['id'],
            'setup_future_usage' => 'off_session',
            'capture_method' => $checkout->mode === WebsiteCheckout::MODE_REQUEST ? 'manual' : 'automatic',
            'payment_method_types' => ['card'],
            'description' => trim(sprintf('Booking %s on %s', $reference ?? '', $checkout->website?->name ?? 'the website')),
            'metadata' => [
                'website_checkout_id' => $checkout->getKey(),
                'reservation_id' => $checkout->reservation_id,
            ],
        ], $key.':intent');

        $checkout->forceFill([
            'stripe_customer_id' => $customer['id'],
            'stripe_payment_intent_id' => $intent['id'],
        ])->save();

        return (string) $intent['client_secret'];
    }

    /**
     * The client secret of a checkout's intent, for a guest coming back to
     * pay: after a refused card, or from the link in their email. Null when
     * there is no intent to pay or Stripe cannot be asked.
     */
    public function clientSecret(WebsiteCheckout $checkout): ?string
    {
        if ($checkout->stripe_payment_intent_id === null) {
            return null;
        }

        try {
            $intent = $this->stripe()->get('/v1/payment_intents/'.$checkout->stripe_payment_intent_id);
        } catch (StripeRequestException) {
            return null;
        }

        return in_array($intent['status'] ?? null, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)
            && is_string($intent['client_secret'] ?? null)
            ? $intent['client_secret']
            : null;
    }

    /**
     * Act on a Stripe event about a website checkout's PaymentIntent. Events
     * about anything else are not this class's, and are ignored.
     */
    public function handle(WebhookEnvelope $event): void
    {
        $checkout = $this->checkoutFor($event->data);

        if ($checkout === null) {
            // Not a checkout's: perhaps a balance taken off-session whose
            // answer never reached Habitat.
            if ($event->type === 'payment_intent.succeeded') {
                $this->recordUnansweredCharge($event->data);
            }

            return;
        }

        match ($event->type) {
            'payment_intent.succeeded' => $this->confirmPaid($checkout, $event->data),
            'payment_intent.amount_capturable_updated' => $this->markRequested($checkout, $event->data),
            'payment_intent.payment_failed' => $this->markFailed($checkout, $event->data),
            default => null,
        };
    }

    /**
     * The money has been taken: record it, settle what it covers, keep the
     * card for the rest, and confirm the booking.
     *
     * A payment that arrives after its hold ran out is still honoured when
     * the nights are free, and refunded in full when they are not.
     *
     * @param  array<string, mixed>  $intent
     */
    public function confirmPaid(WebsiteCheckout $checkout, array $intent): void
    {
        $this->tenancy->runAs($checkout->organization, function () use ($checkout, $intent): void {
            // The card and Stripe's fee, read from the charge before the lock.
            $charge = $this->charge($intent);

            DB::transaction(function () use ($checkout, $intent, $charge): void {
                $locked = WebsiteCheckout::query()->lockForUpdate()->findOrFail($checkout->getKey());

                if (in_array($locked->status, [WebsiteCheckout::STATUS_CONFIRMED, WebsiteCheckout::STATUS_REFUNDED], true)) {
                    return;
                }

                $amount = Money::of((int) ($intent['amount_received'] ?? 0), strtoupper((string) ($intent['currency'] ?? $locked->currency)));
                $held = $locked->reservation_id === null ? null : Reservation::query()->find($locked->reservation_id);

                // The booking this money is for: the hold while it still keeps
                // the nights (staff may have confirmed it by hand); a new one
                // when Habitat's own release gave them back; none when staff
                // cancelled it, which they did on purpose.
                $reservation = match (true) {
                    $held?->blocksInventory() === true => $held,
                    $locked->status === WebsiteCheckout::STATUS_EXPIRED => $this->rebook($locked, $held),
                    default => null,
                };

                $payment = $this->recordPaid($locked, $intent, $amount, $charge, $reservation ?? $held);

                if ($reservation === null) {
                    // The nights went to somebody else while the bank was slow.
                    $this->payments->refund($payment, null, 'The dates were no longer available when the payment arrived.');
                    $locked->forceFill(['status' => WebsiteCheckout::STATUS_REFUNDED])->save();

                    return;
                }

                $this->settle($reservation, $amount);
                $this->keepCard($reservation, $intent, charging: true);

                if ($reservation->status === ReservationStatus::Tentative) {
                    $this->reservations->transitionTo($reservation, ReservationStatus::Confirmed, 'Paid on the website.', 'system');
                }

                $reservation->forceFill(['hold_expires_at' => null])->save();

                $locked->forceFill([
                    'status' => WebsiteCheckout::STATUS_CONFIRMED,
                    'reservation_id' => $reservation->getKey(),
                    'payment_error' => null,
                ])->save();
            });
        });
    }

    /**
     * A request to book has its card authorised: the host's window to answer
     * starts now, and the nights stay held until then.
     *
     * @param  array<string, mixed>  $intent
     */
    public function markRequested(WebsiteCheckout $checkout, array $intent): void
    {
        $this->tenancy->runAs($checkout->organization, function () use ($checkout, $intent): void {
            DB::transaction(function () use ($checkout, $intent): void {
                $locked = WebsiteCheckout::query()->lockForUpdate()->findOrFail($checkout->getKey());

                if ($locked->status !== WebsiteCheckout::STATUS_HOLDING) {
                    return;
                }

                $reservation = $locked->reservation_id === null ? null : Reservation::query()->find($locked->reservation_id);

                if ($reservation === null || ! $reservation->blocksInventory()) {
                    // The booking was cancelled first: let the guest's money go.
                    $this->stripe()->post('/v1/payment_intents/'.$intent['id'].'/cancel', [], 'website-checkout:'.$locked->getKey().':cancel');
                    $locked->forceFill(['status' => WebsiteCheckout::STATUS_EXPIRED])->save();

                    return;
                }

                $amount = Money::of((int) ($intent['amount_capturable'] ?? $intent['amount'] ?? 0), strtoupper((string) ($intent['currency'] ?? $locked->currency)));

                $this->payments->recordAuthorization($amount, $reservation, [
                    'provider' => 'stripe',
                    'method' => 'card',
                    'provider_reference' => (string) $intent['id'],
                    'provider_status' => 'requires_capture',
                    'is_collected_by_us' => true,
                    'instrument_token' => is_string($intent['payment_method'] ?? null) ? $intent['payment_method'] : null,
                    'description' => 'Authorised on '.($locked->website?->name ?? 'the website'),
                    'metadata' => ['website_checkout_id' => $locked->getKey()],
                ]);

                // Kept, but not yet charged from: nothing is taken until the
                // host accepts, and then from this authorisation.
                $this->keepCard($reservation, $intent, charging: false);

                $answerBy = CarbonImmutable::now()->addHours($locked->website?->request_hours ?? 24);
                $reservation->forceFill(['hold_expires_at' => $answerBy])->save();
                $locked->forceFill([
                    'status' => WebsiteCheckout::STATUS_REQUESTED,
                    'hold_expires_at' => $answerBy,
                    'payment_error' => null,
                ])->save();
            });
        });
    }

    /**
     * The card was refused. The hold stands, so the guest can try another
     * card, and the bank's sentence is kept to show them.
     *
     * @param  array<string, mixed>  $intent
     */
    public function markFailed(WebsiteCheckout $checkout, array $intent): void
    {
        if ($checkout->status !== WebsiteCheckout::STATUS_HOLDING) {
            return;
        }

        $checkout->forceFill([
            'payment_error' => Str::limit((string) ($intent['last_payment_error']['message'] ?? 'The payment did not go through.'), 490),
        ])->save();
    }

    /**
     * A hold whose time is up. Stripe is asked first: a payment the webhook
     * has not yet reported confirms the booking rather than losing it.
     */
    public function lapse(WebsiteCheckout $checkout): void
    {
        $intentId = $checkout->stripe_payment_intent_id;

        if ($intentId !== null) {
            try {
                $intent = $this->stripe()->get('/v1/payment_intents/'.$intentId);
            } catch (StripeRequestException $e) {
                if ($checkout->hold_expires_at->addMinutes(self::STRIPE_GRACE_MINUTES)->isFuture()) {
                    return;
                }

                $intent = null;
            }

            $status = $intent['status'] ?? null;

            if ($status === 'succeeded') {
                $this->confirmPaid($checkout, $intent);

                return;
            }

            if ($status === 'requires_capture') {
                $this->markRequested($checkout, $intent);

                return;
            }

            if ($status === 'processing') {
                return;
            }

            if ($status !== null && $status !== 'canceled') {
                try {
                    $this->stripe()->post('/v1/payment_intents/'.$intentId.'/cancel', [], 'website-checkout:'.$checkout->getKey().':cancel');
                } catch (StripeRequestException $e) {
                    Log::warning('A lapsed website payment could not be cancelled at Stripe.', [
                        'website_checkout_id' => $checkout->getKey(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->checkouts->release($checkout);
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $intent
     */
    private function checkoutFor(array $intent): ?WebsiteCheckout
    {
        $intentId = $intent['id'] ?? null;

        if (! is_string($intentId)) {
            return null;
        }

        $checkoutId = $intent['metadata']['website_checkout_id'] ?? null;

        $checkout = is_string($checkoutId)
            ? WebsiteCheckout::query()->find($checkoutId)
            : WebsiteCheckout::query()->where('stripe_payment_intent_id', $intentId)->first();

        // The intent must be the one this checkout started.
        return $checkout !== null && $checkout->stripe_payment_intent_id === $intentId ? $checkout : null;
    }

    /**
     * A new hold for the same stay, when the first ran out before the money
     * arrived. Null when the nights have gone.
     */
    private function rebook(WebsiteCheckout $checkout, ?Reservation $lapsed): ?Reservation
    {
        $listing = $lapsed === null ? null : Listing::query()->with('property')->find($lapsed->listing_id);

        if ($lapsed === null || $listing === null) {
            return null;
        }

        try {
            $reservation = $this->reservations->create(new ReservationRequest(
                listing: $listing,
                checkIn: $lapsed->check_in_date,
                checkOut: $lapsed->check_out_date,
                adults: (int) $lapsed->adults,
                children: (int) $lapsed->children,
                status: ReservationStatus::Tentative,
                source: 'direct',
                guest: $lapsed->guest,
                guestNotes: $lapsed->guest_notes,
                bookedAt: CarbonImmutable::now(),
            ));
        } catch (DatesUnavailableException) {
            return null;
        }

        $reservation->forceFill(['website_id' => $checkout->website_id])->save();
        $this->schedules->standardPlan($reservation);

        return $reservation;
    }

    /**
     * Settle the booking's instalments, earliest first, with what was paid.
     */
    private function settle(Reservation $reservation, Money $amount): void
    {
        $left = $amount;

        $outstanding = PaymentSchedule::query()
            ->where('reservation_id', $reservation->getKey())
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->orderBy('sequence')
            ->get();

        foreach ($outstanding as $instalment) {
            if (! $left->isPositive()) {
                break;
            }

            $part = Money::of(min($left->minorUnits, $instalment->outstandingAmount()->minorUnits), $left->currency);
            $this->schedules->settle($instalment, $part);
            $left = $left->subtract($part);
        }
    }

    /**
     * The payment the money is recorded on, once.
     *
     * Found by the intent first: a request's authorisation captured through
     * Habitat (by staff today, by Accept in stage 4) is already a captured
     * payment, and one captured in Stripe's dashboard is an authorised one to
     * record the capture on. Only money Habitat has no payment for yet becomes
     * a new payment.
     *
     * @param  array<string, mixed>  $intent
     * @param  array{id: string|null, brand: string|null, last4: string|null, fee: int|null}  $charge
     */
    private function recordPaid(WebsiteCheckout $checkout, array $intent, Money $amount, array $charge, ?Reservation $reservation): Payment
    {
        $existing = Payment::query()
            ->where('provider', 'stripe')
            ->where('provider_reference', (string) $intent['id'])
            ->first();

        if ($existing !== null && $existing->status->isCaptured()) {
            return $existing;
        }

        if ($existing !== null && $existing->status === PaymentStatus::Authorized) {
            return $this->payments->recordCapture($existing, $amount, array_filter(['fee_amount' => $charge['fee']]));
        }

        return $this->payments->recordExternalPayment($amount, $reservation, [
            'provider' => 'stripe',
            'method' => 'card',
            'provider_reference' => (string) $intent['id'],
            'is_collected_by_us' => true,
            'instrument_token' => is_string($intent['payment_method'] ?? null) ? $intent['payment_method'] : null,
            'instrument_brand' => $charge['brand'],
            'instrument_last4' => $charge['last4'],
            'fee_amount' => $charge['fee'],
            'description' => 'Paid on '.($checkout->website?->name ?? 'the website'),
            'metadata' => ['website_checkout_id' => $checkout->getKey(), 'stripe_charge' => $charge['id']],
        ]);
    }

    /**
     * A balance Stripe took off-session whose answer never reached Habitat:
     * the instalment is charged again through the payment left waiting, whose
     * idempotency key Stripe answers with the charge it already made.
     *
     * @param  array<string, mixed>  $intent
     */
    private function recordUnansweredCharge(array $intent): void
    {
        $paymentId = $intent['metadata']['payment_id'] ?? null;

        if (! is_string($paymentId)) {
            return;
        }

        $payment = $this->tenancy->withoutScope(fn (): ?Payment => Payment::query()->find($paymentId));
        $scheduleId = $payment?->metadata['payment_schedule_id'] ?? null;

        if ($payment === null || $payment->provider !== 'stripe' || $payment->status !== PaymentStatus::Pending || ! is_string($scheduleId)) {
            return;
        }

        $organization = $this->tenancy->withoutScope(fn (): ?Organization => Organization::query()->find($payment->organization_id));

        if ($organization === null) {
            return;
        }

        $this->tenancy->runAs($organization, function () use ($scheduleId): void {
            $schedule = PaymentSchedule::query()->find($scheduleId);

            if ($schedule === null || $schedule->status === 'paid') {
                return;
            }

            try {
                $this->schedules->charge($schedule);
            } catch (PaymentException $e) {
                // Already settled by the sweep, or refused after all: either
                // way there is nothing more for this event to do.
                Log::info('An unanswered balance charge needed no recording.', ['payment_schedule_id' => $schedule->getKey(), 'error' => $e->getMessage()]);
            }
        });
    }

    /**
     * Keep the guest's Stripe customer and card for the rest of the stay's
     * instalments, and, once the booking is paid for ($charging), have those
     * taken from it when they fall due. A request's card is kept but not
     * charged from: nothing is taken until the host accepts.
     *
     * @param  array<string, mixed>  $intent
     */
    private function keepCard(Reservation $reservation, array $intent, bool $charging): void
    {
        $customer = $intent['customer'] ?? null;
        $card = $intent['payment_method'] ?? null;

        if (! is_string($customer) || ! is_string($card)) {
            return;
        }

        $reservation->guest?->forceFill([
            'stripe_customer_id' => $customer,
            'stripe_payment_method_id' => $card,
        ])->save();

        if (! $charging) {
            return;
        }

        PaymentSchedule::query()
            ->where('reservation_id', $reservation->getKey())
            ->whereIn('status', ['pending', 'partially_paid', 'failed'])
            ->update(['auto_charge' => true]);
    }

    /**
     * The card used and Stripe's fee for an intent's charge. Read once, and
     * never allowed to stop a payment being recorded.
     *
     * @param  array<string, mixed>  $intent
     * @return array{id: string|null, brand: string|null, last4: string|null, fee: int|null}
     */
    private function charge(array $intent): array
    {
        $chargeId = is_string($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : null;
        $none = ['id' => $chargeId, 'brand' => null, 'last4' => null, 'fee' => null];

        if ($chargeId === null) {
            return $none;
        }

        try {
            $charge = $this->stripe()->get('/v1/charges/'.$chargeId, ['expand' => ['balance_transaction']]);
        } catch (StripeRequestException $e) {
            Log::warning('Stripe charge details could not be read; the payment is recorded without them.', [
                'charge' => $chargeId,
                'error' => $e->getMessage(),
            ]);

            return $none;
        }

        $card = $charge['payment_method_details']['card'] ?? [];
        $fee = $charge['balance_transaction'] ?? null;
        $currency = strtolower((string) ($intent['currency'] ?? ''));

        return [
            'id' => $chargeId,
            'brand' => is_string($card['brand'] ?? null) ? $card['brand'] : null,
            'last4' => is_string($card['last4'] ?? null) ? $card['last4'] : null,
            // In the booking's own currency, or not at all.
            'fee' => is_array($fee) && strtolower((string) ($fee['currency'] ?? '')) === $currency ? (int) ($fee['fee'] ?? 0) : null,
        ];
    }

    private function stripe(): StripeClient
    {
        return new StripeClient((string) config('services.stripe.secret'));
    }
}
