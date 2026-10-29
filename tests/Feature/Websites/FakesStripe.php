<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Websites\Models\WebsiteCheckout;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * A small Stripe for the website payment tests: enough of its API for one
 * guest's payment, its signed webhooks, and its idempotency keys, which it
 * answers the way Stripe does — with what happened the first time.
 */
trait FakesStripe
{
    /** What Stripe says the intent is now, when Habitat asks. */
    private string $intentStatus = 'requires_payment_method';

    private bool $stripeDown = false;

    /** How the next off-session charge goes: succeed, lost (taken, but the answer never arrives) or decline. */
    private string $offSession = 'succeed';

    /** @var array<string, array<string, mixed>> what Stripe has already done, by idempotency key */
    private array $done = [];

    private function standInForStripe(): void
    {
        config([
            'services.stripe.key' => 'pk_test_123',
            'services.stripe.secret' => 'sk_test_123',
            'services.stripe.webhook_secret' => 'whsec_test',
        ]);

        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->stripe($request));
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function webhook(string $type, array $object, string $id = 'evt_1', string $secret = 'whsec_test'): TestResponse
    {
        $payload = (string) json_encode(['id' => $id, 'type' => $type, 'created' => time(), 'data' => ['object' => $object]]);
        $at = time();

        return $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$at.',v1='.hash_hmac('sha256', $at.'.'.$payload, $secret),
        ], $payload);
    }

    /**
     * @return list<array{0: Request, 1: mixed}>
     */
    private function offSessionCharges(): array
    {
        return Http::recorded(function (Request $request): bool {
            parse_str($request->body(), $form);

            return $request->url() === 'https://api.stripe.com/v1/payment_intents' && ($form['off_session'] ?? null) === 'true';
        })->values()->all();
    }

    private function intent(WebsiteCheckout $checkout, string $status, array $extra = []): array
    {
        return $extra + [
            'id' => 'pi_1',
            'object' => 'payment_intent',
            'status' => $status,
            'amount' => $checkout->due_now_amount,
            'amount_received' => $status === 'succeeded' ? $checkout->due_now_amount : 0,
            'amount_capturable' => $status === 'requires_capture' ? $checkout->due_now_amount : 0,
            'currency' => 'eur',
            'customer' => 'cus_1',
            'payment_method' => 'pm_1',
            'latest_charge' => 'ch_1',
            'metadata' => ['website_checkout_id' => $checkout->getKey()],
        ];
    }

    private function stripe(Request $request): mixed
    {
        if ($this->stripeDown) {
            return Http::response(['error' => ['type' => 'api_error', 'message' => 'Down']], 503);
        }

        $url = $request->url();
        parse_str($request->body(), $form);

        return match (true) {
            $url === 'https://api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            $url === 'https://api.stripe.com/v1/payment_intents' && ($form['off_session'] ?? null) === 'true' => $this->offSessionCharge($request, $form),
            str_starts_with($url, 'https://api.stripe.com/v1/payment_intents/pi_1/capture') => Http::response([
                'id' => 'pi_1',
                'status' => 'succeeded',
                'amount_received' => (int) $form['amount_to_capture'],
                'latest_charge' => ['id' => 'ch_1', 'balance_transaction' => ['fee' => 172, 'currency' => 'eur']],
            ]),
            $url === 'https://api.stripe.com/v1/payment_intents' => Http::response([
                'id' => 'pi_1',
                'client_secret' => 'pi_1_secret_abc',
                'status' => 'requires_payment_method',
            ]),
            str_starts_with($url, 'https://api.stripe.com/v1/payment_intents/pi_1/cancel') => Http::response(['id' => 'pi_1', 'status' => 'canceled']),
            str_starts_with($url, 'https://api.stripe.com/v1/payment_intents/pi_1') => Http::response(array_filter([
                'id' => 'pi_1',
                'status' => $this->intentStatus,
                'amount' => WebsiteCheckout::query()->first()?->due_now_amount,
                'amount_received' => $this->intentStatus === 'succeeded' ? WebsiteCheckout::query()->first()?->due_now_amount : 0,
                'currency' => 'eur',
                'customer' => 'cus_1',
                'payment_method' => 'pm_1',
                'latest_charge' => 'ch_1',
                'client_secret' => 'pi_1_secret_abc',
                'metadata' => ['website_checkout_id' => WebsiteCheckout::query()->first()?->getKey()],
            ], fn (mixed $value): bool => $value !== null)),
            str_starts_with($url, 'https://api.stripe.com/v1/charges/ch_1') => Http::response([
                'id' => 'ch_1',
                'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242']],
                'balance_transaction' => ['fee' => 172, 'currency' => 'eur'],
            ]),
            $url === 'https://api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded', 'amount' => (int) ($form['amount'] ?? 0)]),
            default => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Not faked: '.$url]], 404),
        };
    }

    private function offSessionCharge(Request $request, array $form): mixed
    {
        $key = $request->header('Idempotency-Key')[0] ?? '';

        if (isset($this->done[$key])) {
            return Http::response($this->done[$key]);
        }

        if ($this->offSession === 'decline') {
            return Http::response(['error' => [
                'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds',
                'message' => 'Your card has insufficient funds.',
            ]], 402);
        }

        $this->done[$key] = [
            'id' => 'pi_balance',
            'status' => 'succeeded',
            'amount_received' => (int) $form['amount'],
            'latest_charge' => ['id' => 'ch_balance'],
            'metadata' => $form['metadata'] ?? [],
        ];

        // Taken at Stripe, but the answer is lost on the way back.
        if ($this->offSession === 'lost') {
            $this->offSession = 'succeed';

            return Http::response(['error' => ['type' => 'api_error', 'message' => 'Gateway timeout']], 504);
        }

        return Http::response($this->done[$key]);
    }
}
