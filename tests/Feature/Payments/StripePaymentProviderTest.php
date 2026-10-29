<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Domain\Integrations\DataObjects\PaymentAuthorizationRequest;
use App\Domain\Integrations\DataObjects\PaymentCaptureRequest;
use App\Domain\Integrations\DataObjects\PaymentResult;
use App\Domain\Integrations\DataObjects\RefundRequest;
use App\Domain\Integrations\Providers\Payments\StripePaymentProvider;
use App\Domain\Integrations\Registries\PaymentProviderRegistry;
use App\Domain\Integrations\Support\StripeClient;
use App\Support\Money\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Habitat's payment contract, carried out by Stripe.
 */
class StripePaymentProviderTest extends TestCase
{
    private StripePaymentProvider $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.stripe.secret' => 'sk_test_abc', 'services.stripe.key' => 'pk_test_abc']);

        $this->stripe = new StripePaymentProvider(new StripeClient('sk_test_abc'));
    }

    public function test_it_is_registered_and_live_only_with_a_secret_key(): void
    {
        $registered = $this->app->make(PaymentProviderRegistry::class)->make('stripe');

        $this->assertSame('stripe', $registered->key());
        $this->assertTrue($registered->isLive());
        $this->assertNull($registered->simulationReason());

        config(['services.stripe.secret' => null]);
        $unconfigured = $this->app->make(PaymentProviderRegistry::class)->make('stripe');

        $this->assertFalse($unconfigured->isLive());
        $this->assertStringContainsString('STRIPE_SECRET_KEY', (string) $unconfigured->simulationReason());
    }

    public function test_authorize_holds_money_on_the_saved_card(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_1', 'status' => 'requires_capture', 'amount' => 10500])]);

        $result = $this->stripe->authorize(new PaymentAuthorizationRequest(
            amount: Money::of(10500, 'EUR'),
            idempotencyKey: 'pay_1:authorize:10500',
            instrumentToken: 'pm_1',
            customerReference: 'cus_1',
            description: 'Deposit for HB-1',
            metadata: ['payment_id' => 'pay_1'],
        ));

        $this->assertSame(PaymentResult::STATUS_AUTHORIZED, $result->status);
        $this->assertSame('pi_1', $result->providerReference);

        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $form);

            return $request->hasHeader('Idempotency-Key', 'pay_1:authorize:10500')
                && $form['amount'] === '10500'
                && $form['currency'] === 'eur'
                && $form['capture_method'] === 'manual'
                && $form['confirm'] === 'true'
                && $form['off_session'] === 'true'
                && $form['payment_method'] === 'pm_1'
                && $form['customer'] === 'cus_1'
                && $form['payment_method_types'] === ['card']
                && $form['metadata']['payment_id'] === 'pay_1';
        });
    }

    public function test_capturing_an_authorization_takes_the_held_money_and_reports_the_fee(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents/pi_1/capture' => Http::response([
            'id' => 'pi_1',
            'status' => 'succeeded',
            'amount_received' => 10500,
            'latest_charge' => [
                'id' => 'ch_1',
                'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242']],
                'balance_transaction' => ['fee' => 172, 'currency' => 'eur'],
            ],
        ])]);

        $result = $this->stripe->capture(new PaymentCaptureRequest(
            amount: Money::of(10500, 'EUR'),
            idempotencyKey: 'pay_1:capture:10500',
            authorizationReference: 'pi_1',
        ));

        $this->assertSame(PaymentResult::STATUS_CAPTURED, $result->status);
        // The intent, not the charge: it is what Habitat keeps on the payment
        // and what a refund is made against.
        $this->assertSame('pi_1', $result->providerReference);
        $this->assertSame('ch_1', $result->raw['charge']);
        $this->assertSame(172, $result->raw['fee']);
        $this->assertSame('4242', $result->instrumentLast4);

        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $form);

            return $form['amount_to_capture'] === '10500' && in_array('latest_charge.balance_transaction', $form['expand'], true);
        });
    }

    public function test_a_fee_in_another_currency_is_not_reported_as_this_ones(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents/pi_1/capture' => Http::response([
            'id' => 'pi_1',
            'status' => 'succeeded',
            'amount_received' => 10500,
            'latest_charge' => ['id' => 'ch_1', 'balance_transaction' => ['fee' => 190, 'currency' => 'usd']],
        ])]);

        $result = $this->stripe->capture(new PaymentCaptureRequest(Money::of(10500, 'EUR'), 'k', authorizationReference: 'pi_1'));

        $this->assertArrayNotHasKey('fee', $result->raw);
    }

    public function test_capturing_without_an_authorization_charges_the_saved_card_off_session(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response([
            'id' => 'pi_2',
            'status' => 'succeeded',
            'amount_received' => 24500,
            'latest_charge' => ['id' => 'ch_2'],
        ])]);

        $result = $this->stripe->capture(new PaymentCaptureRequest(
            amount: Money::of(24500, 'EUR'),
            idempotencyKey: 'pay_2:capture:24500',
            instrumentToken: 'pm_1',
            customerReference: 'cus_1',
        ));

        $this->assertSame(PaymentResult::STATUS_CAPTURED, $result->status);

        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $form);

            // Cards only, captured at once: with Stripe's automatic payment
            // methods a server-side confirm demands a return_url and fails.
            return $form['confirm'] === 'true' && $form['off_session'] === 'true'
                && $form['payment_method'] === 'pm_1' && $form['customer'] === 'cus_1'
                && $form['payment_method_types'] === ['card']
                && $form['capture_method'] === 'automatic';
        });
    }

    public function test_a_decline_is_a_failure_in_the_banks_words(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => [
            'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds',
            'message' => 'Your card has insufficient funds.',
            'payment_intent' => ['id' => 'pi_3'],
        ]], 402)]);

        $result = $this->stripe->capture(new PaymentCaptureRequest(Money::of(100, 'EUR'), 'k', instrumentToken: 'pm_1', customerReference: 'cus_1'));

        $this->assertSame(PaymentResult::STATUS_FAILED, $result->status);
        $this->assertSame('card_declined', $result->failureCode);
        $this->assertSame('Your card has insufficient funds.', $result->failureMessage);
        $this->assertSame('pi_3', $result->providerReference);
    }

    public function test_a_card_that_needs_its_owner_says_so(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => [
            'type' => 'card_error', 'code' => 'authentication_required',
            'message' => 'Your card requires authentication.',
        ]], 402)]);

        $result = $this->stripe->capture(new PaymentCaptureRequest(Money::of(100, 'EUR'), 'k', instrumentToken: 'pm_1', customerReference: 'cus_1'));

        $this->assertSame(PaymentResult::STATUS_FAILED, $result->status);
        $this->assertSame('authentication_required', $result->failureCode);
    }

    public function test_an_authorization_the_bank_wants_confirmed_needs_action(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response([
            'id' => 'pi_4', 'status' => 'requires_action',
            'next_action' => ['type' => 'redirect_to_url', 'redirect_to_url' => ['url' => 'https://hooks.stripe.com/3ds']],
        ])]);

        $result = $this->stripe->authorize(new PaymentAuthorizationRequest(Money::of(100, 'EUR'), 'k', instrumentToken: 'pm_1', customerReference: 'cus_1'));

        $this->assertSame(PaymentResult::STATUS_REQUIRES_ACTION, $result->status);
        $this->assertSame('https://hooks.stripe.com/3ds', $result->redirectUrl);
    }

    public function test_void_cancels_the_intent(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents/pi_1/cancel' => Http::response(['id' => 'pi_1', 'status' => 'canceled'])]);

        $result = $this->stripe->void('pi_1', 'pay_1:void');

        $this->assertSame(PaymentResult::STATUS_VOIDED, $result->status);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'pay_1:void'));
    }

    public function test_a_refund_returns_money_against_the_intent(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded', 'amount' => 5000])]);

        $result = $this->stripe->refund(new RefundRequest('pi_1', Money::of(5000, 'EUR'), 'pay_1:refund:5000', 'Guest cancelled'));

        $this->assertSame(PaymentResult::STATUS_REFUNDED, $result->status);
        $this->assertSame('re_1', $result->providerReference);

        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $form);

            return $form['payment_intent'] === 'pi_1' && $form['amount'] === '5000' && $form['metadata']['reason'] === 'Guest cancelled';
        });
    }

    public function test_a_webhook_is_trusted_only_when_stripe_signed_it(): void
    {
        $at = time();
        $payload = json_encode(['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'created' => $at, 'data' => ['object' => ['id' => 'pi_1']]]);
        $signature = 't='.$at.',v1='.hash_hmac('sha256', $at.'.'.$payload, 'whsec_1');

        $envelope = $this->stripe->parseWebhook((string) $payload, ['stripe-signature' => $signature], 'whsec_1');

        $this->assertNotNull($envelope);
        $this->assertSame('payment_intent.succeeded', $envelope->type);
        $this->assertSame('evt_1', $envelope->providerEventId);
        $this->assertSame('pi_1', $envelope->data['id']);

        $this->assertNull($this->stripe->parseWebhook((string) $payload, ['stripe-signature' => $signature], 'whsec_other'));
    }
}
