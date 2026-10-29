<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Domain\Integrations\Exceptions\StripeRequestException;
use App\Domain\Integrations\Support\StripeClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The HTTP half of the Stripe integration: how a request is sent, how an
 * error is read, and how a webhook is known to be Stripe's.
 */
class StripeClientTest extends TestCase
{
    public function test_a_post_is_form_encoded_with_the_key_and_the_idempotency_key(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'pi_123', 'object' => 'payment_intent'])]);

        $answer = (new StripeClient('sk_test_abc'))->post('/v1/payment_intents', [
            'amount' => 10500,
            'currency' => 'eur',
            'metadata' => ['checkout_id' => '01CHK'],
            'payment_method_types' => ['card'],
        ], 'checkout-01CHK-intent');

        $this->assertSame('pi_123', $answer['id']);

        Http::assertSent(function (Request $request): bool {
            parse_str($request->body(), $form);

            return $request->method() === 'POST'
                && $request->url() === 'https://api.stripe.com/v1/payment_intents'
                && $request->hasHeader('Authorization', 'Bearer sk_test_abc')
                && $request->hasHeader('Idempotency-Key', 'checkout-01CHK-intent')
                && $request->hasHeader('Stripe-Version', StripeClient::API_VERSION)
                && str_contains($request->header('Content-Type')[0] ?? '', 'application/x-www-form-urlencoded')
                && $form['amount'] === '10500'
                && $form['metadata']['checkout_id'] === '01CHK'
                && $form['payment_method_types'] === ['card'];
        });
    }

    public function test_a_get_sends_its_query_and_no_idempotency_key(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'ch_1'])]);

        (new StripeClient('sk_test_abc'))->get('/v1/charges/ch_1', ['expand' => ['balance_transaction']]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.stripe.com/v1/charges/ch_1?')
            && str_contains(urldecode($request->url()), 'expand[0]=balance_transaction')
            && ! $request->hasHeader('Idempotency-Key'));
    }

    public function test_a_card_decline_carries_its_codes_and_is_not_retried(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => [
            'type' => 'card_error',
            'code' => 'card_declined',
            'decline_code' => 'insufficient_funds',
            'message' => 'Your card has insufficient funds.',
        ]], 402)]);

        try {
            (new StripeClient('sk_test_abc'))->post('/v1/payment_intents', ['amount' => 100]);
            $this->fail('A decline must raise.');
        } catch (StripeRequestException $e) {
            $this->assertSame('card_declined', $e->errorCode);
            $this->assertSame('insufficient_funds', $e->declineCode);
            $this->assertSame(402, $e->status);
            $this->assertFalse($e->retryable);
            $this->assertSame('Your card has insufficient funds.', $e->getMessage());
        }
    }

    public function test_a_rate_limit_or_outage_is_retryable(): void
    {
        foreach ([429, 500, 503] as $status) {
            Http::fake(['api.stripe.com/*' => Http::response(['error' => ['type' => 'api_error', 'message' => 'Try again.']], $status)]);

            try {
                (new StripeClient('sk_test_abc'))->post('/v1/refunds', ['payment_intent' => 'pi_1']);
                $this->fail('An error must raise.');
            } catch (StripeRequestException $e) {
                $this->assertTrue($e->retryable, "HTTP $status should be retryable");
            }
        }
    }

    public function test_an_unreachable_stripe_is_retryable(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        try {
            (new StripeClient('sk_test_abc'))->get('/v1/payment_intents/pi_1');
            $this->fail('A connection failure must raise.');
        } catch (StripeRequestException $e) {
            $this->assertTrue($e->retryable);
            $this->assertNull($e->status);
        }
    }

    public function test_writes_are_refused_where_outbound_changes_are_off(): void
    {
        config(['pms.outbound.enabled' => false]);
        Http::fake();

        $this->expectException(StripeRequestException::class);

        try {
            (new StripeClient('sk_test_abc'))->post('/v1/payment_intents', ['amount' => 100]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_webhook_signature_is_checked_against_the_secret_and_the_clock(): void
    {
        $payload = '{"id":"evt_1","type":"payment_intent.succeeded"}';
        $secret = 'whsec_test';
        $now = time();
        $sign = fn (int $at, string $key = 'whsec_test'): string => 't='.$at.',v1='.hash_hmac('sha256', $at.'.'.$payload, $key);

        $this->assertTrue(StripeClient::verifySignature($payload, $sign($now), $secret));
        // Stripe sends one signature per active secret while a secret is rolled.
        $this->assertTrue(StripeClient::verifySignature($payload, $sign($now, 'whsec_old').',v1='.hash_hmac('sha256', $now.'.'.$payload, $secret), $secret));

        $this->assertFalse(StripeClient::verifySignature($payload, $sign($now, 'whsec_forged'), $secret));
        $this->assertFalse(StripeClient::verifySignature($payload.' ', $sign($now), $secret));
        $this->assertFalse(StripeClient::verifySignature($payload, $sign($now - 301), $secret));
        $this->assertFalse(StripeClient::verifySignature($payload, 'v1='.hash_hmac('sha256', $now.'.'.$payload, $secret), $secret));
        $this->assertFalse(StripeClient::verifySignature($payload, '', $secret));
        $this->assertFalse(StripeClient::verifySignature($payload, $sign($now), ''));
    }
}
