<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\Payments;

use App\Domain\Integrations\Contracts\PaymentProviderInterface;
use App\Domain\Integrations\DataObjects\PaymentAuthorizationRequest;
use App\Domain\Integrations\DataObjects\PaymentCaptureRequest;
use App\Domain\Integrations\DataObjects\PaymentInstrument;
use App\Domain\Integrations\DataObjects\PaymentLinkRequest;
use App\Domain\Integrations\DataObjects\PaymentResult;
use App\Domain\Integrations\DataObjects\RefundRequest;
use App\Domain\Integrations\DataObjects\WebhookEnvelope;
use App\Domain\Integrations\Exceptions\StripeRequestException;
use App\Domain\Integrations\Support\StripeClient;
use App\Support\Money\Money;
use DateTimeImmutable;

/**
 * Habitat's payment contract, carried out by Stripe's PaymentIntents.
 *
 * Every operation is a PaymentIntent or a refund of one, and the intent's id
 * is the reference Habitat keeps: an authorisation is an intent waiting to be
 * captured, a capture without one charges the guest's saved card off-session,
 * and a refund is made against the intent.
 *
 * A card refusal comes back as a failed result in the bank's own words, which
 * is what the guest or the operator should read. Anything else — Stripe out
 * of reach, a rate limit — is thrown, so the job that asked can try the same
 * operation again under the same idempotency key without charging twice.
 *
 * Website payments name this provider explicitly; it is not the platform's
 * default processor unless PAYMENTS_DEFAULT_PROVIDER says so.
 */
class StripePaymentProvider implements PaymentProviderInterface
{
    public function __construct(private readonly ?StripeClient $client = null) {}

    public function key(): string
    {
        return 'stripe';
    }

    public function displayName(): string
    {
        return 'Stripe';
    }

    public function isLive(): bool
    {
        return $this->client !== null || filled(config('services.stripe.secret'));
    }

    public function simulationReason(): ?string
    {
        return $this->isLive()
            ? null
            : 'No Stripe secret key is configured (STRIPE_SECRET_KEY), so Stripe cannot be reached.';
    }

    public function capabilities(): array
    {
        return ['authorize', 'capture', 'partial_capture', 'void', 'refund', 'partial_refund', 'stored_instruments', '3ds'];
    }

    public function authorize(PaymentAuthorizationRequest $request): PaymentResult
    {
        return $this->intent($request->amount, $request->idempotencyKey, [
            'capture_method' => 'manual',
            'payment_method' => $request->instrumentToken,
            'customer' => $request->customerReference,
            'description' => $request->description,
            'statement_descriptor_suffix' => $request->statementDescriptor,
            'metadata' => $request->metadata,
        ]);
    }

    public function capture(PaymentCaptureRequest $request): PaymentResult
    {
        if ($request->authorizationReference !== null) {
            return $this->attempt(fn (): PaymentResult => $this->result(
                $this->client()->post(
                    '/v1/payment_intents/'.$request->authorizationReference.'/capture',
                    [
                        'amount_to_capture' => $request->amount->minorUnits,
                        'expand' => ['latest_charge.balance_transaction'],
                    ],
                    $request->idempotencyKey,
                ),
                $request->amount,
            ));
        }

        // No hold to draw on: the guest's saved card, charged while they are
        // not there — a balance falling due, say.
        return $this->intent($request->amount, $request->idempotencyKey, [
            'capture_method' => 'automatic',
            'payment_method' => $request->instrumentToken,
            'customer' => $request->customerReference,
            'description' => $request->description,
            'metadata' => $request->metadata,
        ]);
    }

    public function void(string $providerReference, ?string $idempotencyKey = null): PaymentResult
    {
        return $this->attempt(function () use ($providerReference, $idempotencyKey): PaymentResult {
            $body = $this->client()->post('/v1/payment_intents/'.$providerReference.'/cancel', [], $idempotencyKey);

            return PaymentResult::voided((string) ($body['id'] ?? $providerReference), $body);
        });
    }

    public function refund(RefundRequest $request): PaymentResult
    {
        return $this->attempt(function () use ($request): PaymentResult {
            $reference = $request->paymentReference;

            $body = $this->client()->post('/v1/refunds', array_filter([
                str_starts_with($reference, 'ch_') ? 'charge' : 'payment_intent' => $reference,
                'amount' => $request->amount->minorUnits,
                'metadata' => array_filter($request->metadata + ['reason' => $request->reason]),
            ], fn (mixed $value): bool => $value !== null && $value !== []), $request->idempotencyKey);

            if (($body['status'] ?? null) === 'failed' || ($body['status'] ?? null) === 'canceled') {
                return PaymentResult::failed('refund_failed', 'Stripe could not make the refund.', (string) ($body['id'] ?? ''), $body);
            }

            // Pending counts: Stripe has accepted it and the money is on its way.
            return PaymentResult::refunded((string) $body['id'], $request->amount, $body);
        });
    }

    public function createPaymentLink(PaymentLinkRequest $request): PaymentResult
    {
        return PaymentResult::failed('unsupported', 'Stripe payment links are not offered; guests pay on the booking website.');
    }

    public function storeInstrument(PaymentInstrument $instrument): PaymentResult
    {
        return PaymentResult::failed('unsupported', 'A card is saved by the guest paying on the booking website, never typed into Habitat.');
    }

    public function parseWebhook(string $payload, array $headers, string $signingSecret): ?WebhookEnvelope
    {
        $headers = array_change_key_case($headers);
        $signature = $headers['stripe-signature'] ?? '';
        $signature = is_array($signature) ? (string) ($signature[0] ?? '') : (string) $signature;

        if (! StripeClient::verifySignature($payload, $signature, $signingSecret)) {
            return null;
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return null;
        }

        return new WebhookEnvelope(
            type: $event['type'],
            providerEventId: $event['id'],
            data: is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [],
            occurredAt: is_int($event['created'] ?? null) ? (new DateTimeImmutable)->setTimestamp($event['created']) : null,
        );
    }

    // ------------------------------------------------------------------

    /**
     * Create and confirm a PaymentIntent against a saved card.
     *
     * @param  array<string, mixed>  $fields
     */
    private function intent(Money $amount, string $idempotencyKey, array $fields): PaymentResult
    {
        return $this->attempt(fn (): PaymentResult => $this->result(
            $this->client()->post('/v1/payment_intents', array_filter([
                'amount' => $amount->minorUnits,
                'currency' => strtolower($amount->currency),
                'confirm' => 'true',
                'off_session' => 'true',
                // Cards only. With Stripe's automatic payment methods a
                // confirm made on the server demands a return_url, and the
                // guest is not there to be returned anywhere.
                'payment_method_types' => ['card'],
                'expand' => ['latest_charge.balance_transaction'],
            ] + $fields, fn (mixed $value): bool => $value !== null && $value !== []), $idempotencyKey),
            $amount,
        ));
    }

    /**
     * @param  callable(): PaymentResult  $call
     */
    private function attempt(callable $call): PaymentResult
    {
        try {
            return $call();
        } catch (StripeRequestException $e) {
            if (! $e->isCardError()) {
                throw $e;
            }

            $intent = $e->body['error']['payment_intent']['id'] ?? null;

            return PaymentResult::failed(
                $e->errorCode ?? 'card_error',
                $e->getMessage(),
                is_string($intent) ? $intent : null,
                $e->body,
            );
        }
    }

    /**
     * A PaymentIntent, as Habitat's result.
     *
     * @param  array<string, mixed>  $intent
     */
    private function result(array $intent, Money $amount): PaymentResult
    {
        $id = (string) ($intent['id'] ?? '');

        return match ($intent['status'] ?? null) {
            'requires_capture' => PaymentResult::authorized($id, $amount, $intent),
            'succeeded' => $this->captured($intent, $amount),
            'processing' => PaymentResult::pending($id, $amount, $intent),
            'requires_action' => PaymentResult::requiresAction(
                $id,
                (string) ($intent['next_action']['redirect_to_url']['url'] ?? ''),
                $amount,
                $intent,
            ),
            default => PaymentResult::failed(
                (string) ($intent['last_payment_error']['code'] ?? 'payment_failed'),
                (string) ($intent['last_payment_error']['message'] ?? 'The payment did not go through.'),
                $id,
                $intent,
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function captured(array $intent, Money $amount): PaymentResult
    {
        $charge = is_array($intent['latest_charge'] ?? null) ? $intent['latest_charge'] : [];
        $card = $charge['payment_method_details']['card'] ?? [];
        $raw = ['payment_intent' => $intent['id'] ?? null, 'charge' => $charge['id'] ?? ($intent['latest_charge'] ?? null)];

        // Stripe's fee is in the account's settlement currency. Recorded only
        // when that is the booking's own, never as a number in the wrong unit.
        $fee = $charge['balance_transaction'] ?? null;

        if (is_array($fee) && isset($fee['fee']) && strtolower((string) ($fee['currency'] ?? '')) === strtolower($amount->currency)) {
            $raw['fee'] = (int) $fee['fee'];
        }

        return PaymentResult::captured(
            (string) ($intent['id'] ?? ''),
            Money::of((int) ($intent['amount_received'] ?? $amount->minorUnits), $amount->currency),
            $raw,
            is_string($intent['payment_method'] ?? null) ? $intent['payment_method'] : null,
            $card['last4'] ?? null,
            $card['brand'] ?? null,
        );
    }

    private function client(): StripeClient
    {
        return $this->client ?? new StripeClient((string) config('services.stripe.secret'));
    }
}
