<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Guests\Models\Guest;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentSchedule;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Paying on a booking website: Stripe takes the money in the guest's browser
 * and tells Habitat by webhook, which is the only word Habitat takes for it.
 */
class SiteStripePaymentTest extends TestCase
{
    use BuildsSites;
    use FakesStripe;
    use RefreshDatabase;

    private Organization $harbour;

    private Website $site;

    private Listing $flat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->standInForStripe();

        $this->harbour = $this->createOrganization(['name' => 'Harbour Homes', 'base_currency' => 'EUR']);

        $this->site = Website::query()->create([
            'slug' => 'brand',
            'name' => 'Stay With Us',
            'kind' => Website::KIND_BRAND,
            'status' => Website::STATUS_LIVE,
            'hold_minutes' => 30,
            'request_hours' => 24,
        ]);

        $this->flat = $this->listing($this->harbour, 'Sea View Flat', ['instant_book' => true]);
        $this->show($this->flat);

        $this->app->make(TenantContext::class)->clear();
    }

    public function test_an_instant_booking_is_paid_and_confirmed_through_a_signed_webhook(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $response = $this->checkout($this->flat, $checkIn, $checkIn->addDays(3))
            ->assertCreated()
            ->assertJsonPath('client_secret', 'pi_1_secret_abc')
            ->assertJsonPath('publishable_key', 'pk_test_123');

        $checkout = WebsiteCheckout::query()->sole();
        $this->assertSame('pi_1', $checkout->stripe_payment_intent_id);
        $this->assertSame('cus_1', $checkout->stripe_customer_id);
        $this->assertNotNull($response->json('booking_url'));

        // The intent asks for what is due now, and keeps the card for the rest.
        Http::assertSent(function (Request $request) use ($checkout): bool {
            parse_str($request->body(), $form);

            return $request->url() === 'https://api.stripe.com/v1/payment_intents'
                && ! isset($form['off_session'])
                && $form['amount'] === (string) $checkout->due_now_amount
                && $form['currency'] === 'eur'
                && $form['customer'] === 'cus_1'
                && $form['setup_future_usage'] === 'off_session'
                && $form['capture_method'] === 'automatic'
                && $form['metadata']['website_checkout_id'] === $checkout->getKey();
        });

        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'))->assertOk();

        $checkout->refresh();
        $this->assertSame(WebsiteCheckout::STATUS_CONFIRMED, $checkout->status);

        $reservation = $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id));
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertNull($reservation->hold_expires_at);

        $payment = $this->withoutScope(fn () => Payment::query()->where('reservation_id', $reservation->getKey())->sole());
        $this->assertSame('stripe', $payment->provider);
        $this->assertSame('pi_1', $payment->provider_reference);
        $this->assertSame(PaymentStatus::Captured, $payment->status);
        $this->assertSame($checkout->due_now_amount, (int) $payment->captured_amount);
        $this->assertSame(172, (int) $payment->fee_amount);
        $this->assertSame('4242', $payment->instrument_last4);

        $schedule = $this->withoutScope(fn () => PaymentSchedule::query()->where('reservation_id', $reservation->getKey())->orderBy('sequence')->get());
        $this->assertSame('paid', $schedule[0]->status);
        $this->assertSame('pending', $schedule[1]->status);
        // The rest is taken from the saved card when it falls due.
        $this->assertTrue((bool) $schedule[1]->auto_charge);

        $guest = $this->withoutScope(fn () => Guest::query()->findOrFail($reservation->guest_id));
        $this->assertSame('cus_1', $guest->stripe_customer_id);
        $this->assertSame('pm_1', $guest->stripe_payment_method_id);
    }

    public function test_a_forged_webhook_is_refused_and_changes_nothing(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->flat, $checkIn, $checkIn->addDays(3))->assertCreated();
        $checkout = WebsiteCheckout::query()->sole();

        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'), secret: 'whsec_forged')->assertStatus(400);

        $this->assertSame(WebsiteCheckout::STATUS_HOLDING, $checkout->fresh()->status);
        $this->assertSame(0, $this->withoutScope(fn () => Payment::query()->count()));
    }

    public function test_a_repeated_event_is_handled_once(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->flat, $checkIn, $checkIn->addDays(3))->assertCreated();
        $checkout = WebsiteCheckout::query()->sole();

        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'), id: 'evt_same')->assertOk();
        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'), id: 'evt_same')->assertOk();
        // A different event for the same payment is no second payment either.
        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'), id: 'evt_other')->assertOk();

        $this->assertSame(1, $this->withoutScope(fn () => Payment::query()->count()));
    }

    public function test_a_payment_after_the_hold_lapsed_is_kept_when_the_nights_are_still_free(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->flat, $checkIn, $checkIn->addDays(3))->assertCreated();
        $checkout = WebsiteCheckout::query()->sole();

        $this->travel(31)->minutes();
        $this->artisan('websites:release-expired-holds')->assertSuccessful();
        $this->assertSame(WebsiteCheckout::STATUS_EXPIRED, $checkout->fresh()->status);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/payment_intents/pi_1/cancel');

        // The guest's bank was slow, and the payment still went through.
        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'))->assertOk();

        $checkout->refresh();
        $this->assertSame(WebsiteCheckout::STATUS_CONFIRMED, $checkout->status);
        $booking = $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id));
        $this->assertSame(ReservationStatus::Confirmed, $booking->status);
        $this->assertSame($checkIn->toDateString(), $booking->check_in_date->toDateString());
    }

    public function test_a_payment_after_the_nights_went_to_someone_else_is_refunded_in_full(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->flat, $checkIn, $checkIn->addDays(3))->assertCreated();
        $checkout = WebsiteCheckout::query()->sole();

        $this->travel(31)->minutes();
        $this->artisan('websites:release-expired-holds')->assertSuccessful();
        $this->book($this->flat, $checkIn, $checkIn->addDays(3));

        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'))->assertOk();

        $this->assertSame(WebsiteCheckout::STATUS_REFUNDED, $checkout->fresh()->status);
        Http::assertSent(function (Request $request) use ($checkout): bool {
            parse_str($request->body(), $form);

            return $request->url() === 'https://api.stripe.com/v1/refunds'
                && $form['payment_intent'] === 'pi_1'
                && $form['amount'] === (string) $checkout->due_now_amount;
        });

        $payment = $this->withoutScope(fn () => Payment::query()->sole());
        $this->assertSame($checkout->due_now_amount, (int) $payment->refunded_amount);
    }

    public function test_a_lapsed_hold_already_paid_at_stripe_is_confirmed_not_released(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->flat, $checkIn, $checkIn->addDays(3))->assertCreated();
        $checkout = WebsiteCheckout::query()->sole();

        // Paid, but the webhook has not arrived when the hold runs out.
        $this->intentStatus = 'succeeded';
        $this->travel(31)->minutes();
        $this->artisan('websites:release-expired-holds')->assertSuccessful();

        $this->assertSame(WebsiteCheckout::STATUS_CONFIRMED, $checkout->fresh()->status);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/cancel'));
    }

    public function test_a_request_is_held_for_the_host_once_the_card_is_authorised(): void
    {
        $barn = $this->listing($this->harbour, 'Old Barn', ['instant_book' => false]);
        $this->show($barn);
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($barn, $checkIn, $checkIn->addDays(2))->assertCreated()->assertJsonPath('mode', 'request');
        $checkout = WebsiteCheckout::query()->sole();

        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $form);

            return $request->url() === 'https://api.stripe.com/v1/payment_intents' && $form['capture_method'] === 'manual';
        });

        $this->webhook('payment_intent.amount_capturable_updated', $this->intent($checkout, 'requires_capture'))->assertOk();

        $checkout->refresh();
        $this->assertSame(WebsiteCheckout::STATUS_REQUESTED, $checkout->status);
        $this->assertEqualsWithDelta(now()->addHours(24)->getTimestamp(), $checkout->hold_expires_at->getTimestamp(), 5);

        $reservation = $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id));
        $this->assertSame(ReservationStatus::Tentative, $reservation->status);
        $this->assertSame($checkout->hold_expires_at->getTimestamp(), $reservation->hold_expires_at->getTimestamp());

        $payment = $this->withoutScope(fn () => Payment::query()->sole());
        $this->assertSame(PaymentStatus::Authorized, $payment->status);
        $this->assertSame('stripe', $payment->provider);
        $this->assertSame(0, (int) $payment->captured_amount);
    }

    public function test_a_declined_card_keeps_the_hold_and_says_why(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->flat, $checkIn, $checkIn->addDays(3))->assertCreated();
        $checkout = WebsiteCheckout::query()->sole();

        $this->webhook('payment_intent.payment_failed', $this->intent($checkout, 'requires_payment_method', [
            'last_payment_error' => ['code' => 'card_declined', 'message' => 'Your card was declined.'],
        ]))->assertOk();

        $checkout->refresh();
        $this->assertSame(WebsiteCheckout::STATUS_HOLDING, $checkout->status);
        $this->assertSame('Your card was declined.', $checkout->payment_error);
    }

    public function test_the_balance_is_charged_off_session_when_it_falls_due(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->flat, $checkIn, $checkIn->addDays(3))->assertCreated();
        $checkout = WebsiteCheckout::query()->sole();
        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'))->assertOk();

        $this->travelTo($checkIn->subDays(14)->setTime(9, 0));
        $this->artisan('payments:process-due-schedules')->assertSuccessful();

        $balance = $this->withoutScope(fn () => PaymentSchedule::query()->where('reservation_id', $checkout->reservation_id)->orderByDesc('sequence')->first());
        $this->assertSame('paid', $balance->status);

        Http::assertSent(function (Request $request) use ($balance): bool {
            parse_str($request->body(), $form);

            return $request->url() === 'https://api.stripe.com/v1/payment_intents'
                && ($form['off_session'] ?? null) === 'true'
                && $form['customer'] === 'cus_1'
                && $form['payment_method'] === 'pm_1'
                && $form['amount'] === (string) $balance->amount;
        });

        $this->assertSame(2, $this->withoutScope(fn () => Payment::query()->where('provider', 'stripe')->where('status', PaymentStatus::Captured->value)->count()));
    }

    public function test_a_refund_goes_back_through_stripe(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->flat, $checkIn, $checkIn->addDays(3))->assertCreated();
        $checkout = WebsiteCheckout::query()->sole();
        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'))->assertOk();

        $this->actingForOrganization($this->harbour);
        $payment = Payment::query()->sole();
        $this->app->make(PaymentService::class)->refund($payment, reason: 'Guest cancelled');

        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $form);

            return $request->url() === 'https://api.stripe.com/v1/refunds' && $form['payment_intent'] === 'pi_1';
        });
        $this->assertSame((int) $payment->captured_amount, (int) $payment->fresh()->refunded_amount);
    }

    public function test_a_hold_is_given_back_at_once_when_stripe_cannot_be_reached(): void
    {
        $this->stripeDown = true;

        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->flat, $checkIn, $checkIn->addDays(3))
            ->assertStatus(503)
            ->assertJsonPath('message', 'The payment could not be started. Please try again in a moment.');

        $checkout = WebsiteCheckout::query()->sole();
        $this->assertSame(WebsiteCheckout::STATUS_FAILED, $checkout->status);
        $this->assertSame(
            ReservationStatus::Cancelled,
            $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id))->status,
        );
    }

    public function test_an_authorised_request_is_not_charged_before_the_host_answers(): void
    {
        $barn = $this->listing($this->harbour, 'Old Barn', ['instant_book' => false]);
        $this->show($barn);
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($barn, $checkIn, $checkIn->addDays(2))->assertCreated();
        $checkout = WebsiteCheckout::query()->sole();
        $this->webhook('payment_intent.amount_capturable_updated', $this->intent($checkout, 'requires_capture'))->assertOk();

        // The deposit is due today, and the card is only authorised for it.
        $this->artisan('payments:process-due-schedules')->assertSuccessful();

        $this->assertSame([], $this->offSessionCharges());
        $this->assertSame(0, $this->withoutScope(fn () => PaymentSchedule::query()->where('auto_charge', true)->count()));
    }

    public function test_a_balance_charge_whose_answer_was_lost_is_retried_under_the_same_key(): void
    {
        $checkout = $this->paidInstant();
        $this->offSession = 'lost';

        $this->artisan('payments:process-due-schedules')->assertSuccessful();
        $this->assertNotSame('paid', $this->balance($checkout)->status);

        $this->travel(1)->hours();
        $this->artisan('payments:process-due-schedules')->assertSuccessful();

        $charges = $this->offSessionCharges();
        $this->assertCount(2, $charges);
        // The second asks about the first rather than charging again.
        $this->assertSame($charges[0][0]->header('Idempotency-Key'), $charges[1][0]->header('Idempotency-Key'));
        $this->assertSame('paid', $this->balance($checkout)->status);
        $this->assertSame(2, $this->withoutScope(fn () => Payment::query()->where('status', PaymentStatus::Captured->value)->count()));
    }

    public function test_a_lost_balance_charge_is_recorded_from_stripes_webhook(): void
    {
        $checkout = $this->paidInstant();
        $this->offSession = 'lost';
        $this->artisan('payments:process-due-schedules')->assertSuccessful();

        $pending = $this->withoutScope(fn () => Payment::query()->where('status', PaymentStatus::Pending->value)->sole());

        $this->webhook('payment_intent.succeeded', [
            'id' => 'pi_balance',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount_received' => $this->balance($checkout)->amount,
            'currency' => 'eur',
            'metadata' => ['payment_id' => $pending->getKey()],
        ], id: 'evt_balance')->assertOk();

        $this->assertSame('paid', $this->balance($checkout)->status);
        $this->assertSame(PaymentStatus::Captured, $this->withoutScope(fn () => $pending->fresh())->status);

        $this->travel(1)->hours();
        $this->artisan('payments:process-due-schedules')->assertSuccessful();
        $this->assertCount(2, $this->offSessionCharges(), 'only the lost charge and its recorded replay');
    }

    public function test_a_declined_balance_is_not_tried_again_every_hour(): void
    {
        $checkout = $this->paidInstant();
        $this->offSession = 'decline';

        $this->artisan('payments:process-due-schedules')->assertSuccessful();

        $balance = $this->balance($checkout);
        $this->assertSame('failed', $balance->status);
        $this->assertFalse((bool) $balance->auto_charge);
        $this->assertSame('Your card has insufficient funds.', $balance->last_failure_message);

        $this->travel(1)->hours();
        $this->artisan('payments:process-due-schedules')->assertSuccessful();
        $this->assertCount(1, $this->offSessionCharges());
    }

    public function test_a_request_captured_by_staff_is_recorded_once(): void
    {
        $barn = $this->listing($this->harbour, 'Old Barn', ['instant_book' => false]);
        $this->show($barn);
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($barn, $checkIn, $checkIn->addDays(2))->assertCreated();
        $checkout = WebsiteCheckout::query()->sole();
        $this->webhook('payment_intent.amount_capturable_updated', $this->intent($checkout, 'requires_capture'))->assertOk();

        $this->actingForOrganization($this->harbour);
        $this->app->make(PaymentService::class)->capture(Payment::query()->sole());
        $this->app->make(TenantContext::class)->clear();

        // Stripe then reports the capture it was asked for.
        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'), id: 'evt_captured')->assertOk();

        $this->assertSame(1, $this->withoutScope(fn () => Payment::query()->count()));
        $this->assertSame(WebsiteCheckout::STATUS_CONFIRMED, $checkout->fresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id))->status);
        $this->assertSame('paid', $this->withoutScope(fn () => PaymentSchedule::query()->where('reservation_id', $checkout->reservation_id)->orderBy('sequence')->first())->status);
    }

    public function test_a_payment_for_a_hold_staff_confirmed_keeps_that_booking(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->flat, $checkIn, $checkIn->addDays(3))->assertCreated();
        $checkout = WebsiteCheckout::query()->sole();

        $this->actingForOrganization($this->harbour);
        $this->app->make(ReservationService::class)->transitionTo(Reservation::query()->sole(), ReservationStatus::Confirmed);
        $this->app->make(TenantContext::class)->clear();

        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'))->assertOk();

        $this->assertSame(WebsiteCheckout::STATUS_CONFIRMED, $checkout->fresh()->status);
        $this->assertSame($checkout->reservation_id, $checkout->fresh()->reservation_id);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/refunds');
    }

    public function test_a_payment_for_a_hold_staff_cancelled_is_refunded(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->flat, $checkIn, $checkIn->addDays(3))->assertCreated();
        $checkout = WebsiteCheckout::query()->sole();

        $this->actingForOrganization($this->harbour);
        $this->app->make(ReservationService::class)->cancel(Reservation::query()->sole(), 'Owner blocked the dates');
        $this->app->make(TenantContext::class)->clear();

        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'))->assertOk();

        // Staff cancelled it on purpose: it is not booked again behind their back.
        $this->assertSame(WebsiteCheckout::STATUS_REFUNDED, $checkout->fresh()->status);
        $this->assertSame(1, $this->withoutScope(fn () => Reservation::query()->count()));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/refunds');
    }

    public function test_the_webhook_allowance_is_counted_per_sender(): void
    {
        config(['pms.sites.stripe_webhook_requests_per_minute' => 2]);

        foreach ([1, 2] as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->postJson('/webhooks/stripe', [])->assertStatus(400);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->postJson('/webhooks/stripe', [])->assertStatus(429);
        // Somebody hammering the address does not hold Stripe's own deliveries back.
        $this->withServerVariables(['REMOTE_ADDR' => '54.187.174.169'])->postJson('/webhooks/stripe', [])->assertStatus(400);
    }

    // ------------------------------------------------------------------

    private function checkout(Listing $listing, CarbonImmutable $checkIn, CarbonImmutable $checkOut): TestResponse
    {
        $quote = $this->postJson('/sites/brand/api/listings/'.$listing->slug.'/quote', [
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'adults' => 2,
        ])->assertOk()->json('quote_id');

        return $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/sites/brand/api/checkouts', [
            'quote_id' => $quote,
            'first_name' => 'Ana',
            'last_name' => 'Costa',
            'email' => 'ana@example.test',
            'phone' => '+351 912 345 678',
            'accept_policies' => true,
        ]);
    }

    private function paidInstant(): WebsiteCheckout
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->flat, $checkIn, $checkIn->addDays(3))->assertCreated();
        $checkout = WebsiteCheckout::query()->sole();
        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'))->assertOk();
        $this->travelTo($checkIn->subDays(14)->setTime(9, 0));

        return $checkout;
    }

    private function balance(WebsiteCheckout $checkout): PaymentSchedule
    {
        return $this->withoutScope(fn () => PaymentSchedule::query()->where('reservation_id', $checkout->reservation_id)->orderByDesc('sequence')->first());
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function withoutScope(callable $read): mixed
    {
        return $this->app->make(TenantContext::class)->withoutScope($read);
    }
}
