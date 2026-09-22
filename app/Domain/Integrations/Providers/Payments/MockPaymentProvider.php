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
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A working local payment processor.
 *
 * It is not a stub: it keeps real state, and it enforces the invariants a real
 * processor enforces — you cannot capture more than you authorised, you cannot
 * refund more than you captured, and a repeated idempotency key returns the
 * original transaction rather than creating a second one.
 *
 * That makes the payment, refund and reconciliation paths genuinely exercised
 * in development and in tests. It reports `isLive() === false`, and every
 * surface that shows a payment connection uses that flag to say plainly that
 * the processor is simulated.
 *
 * Deterministic test hooks: an amount whose minor units end in the patterns
 * below forces a specific outcome, which is how the failure paths are tested.
 */
class MockPaymentProvider implements PaymentProviderInterface
{
    private const TABLE = 'simulated_payment_transactions';

    /** Amounts ending in these minor units trigger a scripted outcome. */
    private const DECLINE_SUFFIX = 51;

    private const REQUIRES_ACTION_SUFFIX = 52;

    private const INSUFFICIENT_FUNDS_SUFFIX = 53;

    public function key(): string
    {
        return 'mock';
    }

    public function displayName(): string
    {
        return 'Simulated processor (development)';
    }

    public function isLive(): bool
    {
        return false;
    }

    public function capabilities(): array
    {
        return [
            'authorize', 'capture', 'partial_capture', 'void',
            'refund', 'partial_refund', 'payment_link', 'stored_instruments',
        ];
    }

    public function authorize(PaymentAuthorizationRequest $request): PaymentResult
    {
        if ($existing = $this->findByIdempotencyKey('authorization', $request->idempotencyKey)) {
            return PaymentResult::authorized(
                $existing->reference,
                Money::of((int) $existing->amount, $existing->currency),
                ['replayed' => true],
            );
        }

        if ($scripted = $this->scriptedFailure($request->amount)) {
            return $scripted;
        }

        $reference = $this->reference('auth');

        DB::table(self::TABLE)->insert([
            'id' => (string) Str::ulid(),
            'reference' => $reference,
            'kind' => 'authorization',
            'status' => 'authorized',
            'parent_reference' => null,
            'amount' => $request->amount->minorUnits,
            'currency' => $request->amount->currency,
            'captured_amount' => 0,
            'refunded_amount' => 0,
            'customer_reference' => $request->customerReference,
            'instrument_token' => $request->instrumentToken,
            'instrument_last4' => $this->last4($request->instrumentToken),
            'instrument_brand' => $this->brand($request->instrumentToken),
            'idempotency_key' => $request->idempotencyKey,
            'description' => $request->description,
            'metadata' => json_encode($request->metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PaymentResult::authorized($reference, $request->amount, ['simulated' => true]);
    }

    public function capture(PaymentCaptureRequest $request): PaymentResult
    {
        if ($existing = $this->findByIdempotencyKey('capture', $request->idempotencyKey)) {
            return PaymentResult::captured(
                $existing->reference,
                Money::of((int) $existing->amount, $existing->currency),
                ['replayed' => true],
                $existing->instrument_token,
                $existing->instrument_last4,
                $existing->instrument_brand,
            );
        }

        if ($scripted = $this->scriptedFailure($request->amount)) {
            return $scripted;
        }

        return DB::transaction(function () use ($request): PaymentResult {
            $authorization = null;

            if ($request->authorizationReference !== null) {
                $authorization = DB::table(self::TABLE)
                    ->where('reference', $request->authorizationReference)
                    ->lockForUpdate()
                    ->first();

                if ($authorization === null) {
                    return PaymentResult::failed('authorization_not_found', 'No such authorization.');
                }

                if ($authorization->status === 'voided') {
                    return PaymentResult::failed('authorization_voided', 'That authorization has been voided.');
                }

                $remaining = (int) $authorization->amount - (int) $authorization->captured_amount;

                if ($request->amount->minorUnits > $remaining) {
                    return PaymentResult::failed(
                        'capture_exceeds_authorization',
                        sprintf(
                            'Cannot capture %s when only %s remains authorised.',
                            $request->amount->toDecimal(),
                            Money::of($remaining, $authorization->currency)->toDecimal(),
                        ),
                    );
                }

                if ($authorization->currency !== $request->amount->currency) {
                    return PaymentResult::failed(
                        'currency_mismatch',
                        'The capture currency does not match the authorization.',
                    );
                }
            }

            $reference = $this->reference('cap');

            DB::table(self::TABLE)->insert([
                'id' => (string) Str::ulid(),
                'reference' => $reference,
                'kind' => 'capture',
                'status' => 'captured',
                'parent_reference' => $request->authorizationReference,
                'amount' => $request->amount->minorUnits,
                'currency' => $request->amount->currency,
                'captured_amount' => $request->amount->minorUnits,
                'refunded_amount' => 0,
                'customer_reference' => $request->customerReference ?? $authorization?->customer_reference,
                'instrument_token' => $request->instrumentToken ?? $authorization?->instrument_token,
                'instrument_last4' => $this->last4($request->instrumentToken ?? $authorization?->instrument_token),
                'instrument_brand' => $this->brand($request->instrumentToken ?? $authorization?->instrument_token),
                'idempotency_key' => $request->idempotencyKey,
                'description' => $request->description,
                'metadata' => json_encode($request->metadata),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($authorization !== null) {
                DB::table(self::TABLE)
                    ->where('reference', $authorization->reference)
                    ->update([
                        'captured_amount' => (int) $authorization->captured_amount + $request->amount->minorUnits,
                        'status' => ((int) $authorization->captured_amount + $request->amount->minorUnits) >= (int) $authorization->amount
                            ? 'captured'
                            : 'partially_captured',
                        'updated_at' => now(),
                    ]);
            }

            return PaymentResult::captured(
                $reference,
                $request->amount,
                ['simulated' => true],
                $request->instrumentToken ?? $authorization?->instrument_token,
                $this->last4($request->instrumentToken ?? $authorization?->instrument_token),
                $this->brand($request->instrumentToken ?? $authorization?->instrument_token),
            );
        });
    }

    public function void(string $providerReference, ?string $idempotencyKey = null): PaymentResult
    {
        $row = DB::table(self::TABLE)->where('reference', $providerReference)->first();

        if ($row === null) {
            return PaymentResult::failed('not_found', 'No such transaction.');
        }

        if ((int) $row->captured_amount > 0) {
            return PaymentResult::failed(
                'already_captured',
                'This authorization has been captured and can no longer be voided. Refund it instead.',
            );
        }

        DB::table(self::TABLE)
            ->where('reference', $providerReference)
            ->update(['status' => 'voided', 'updated_at' => now()]);

        return PaymentResult::voided($providerReference, ['simulated' => true]);
    }

    public function refund(RefundRequest $request): PaymentResult
    {
        if ($existing = $this->findByIdempotencyKey('refund', $request->idempotencyKey)) {
            return PaymentResult::refunded(
                $existing->reference,
                Money::of((int) $existing->amount, $existing->currency),
                ['replayed' => true],
            );
        }

        return DB::transaction(function () use ($request): PaymentResult {
            $payment = DB::table(self::TABLE)
                ->where('reference', $request->paymentReference)
                ->lockForUpdate()
                ->first();

            if ($payment === null) {
                return PaymentResult::failed('not_found', 'No such payment.');
            }

            if ($payment->currency !== $request->amount->currency) {
                return PaymentResult::failed('currency_mismatch', 'Refund currency does not match the payment.');
            }

            $refundable = (int) $payment->captured_amount - (int) $payment->refunded_amount;

            if ($request->amount->minorUnits > $refundable) {
                return PaymentResult::failed(
                    'refund_exceeds_capture',
                    sprintf(
                        'Cannot refund %s when only %s of this payment remains refundable.',
                        $request->amount->toDecimal(),
                        Money::of(max(0, $refundable), $payment->currency)->toDecimal(),
                    ),
                );
            }

            $reference = $this->reference('ref');

            DB::table(self::TABLE)->insert([
                'id' => (string) Str::ulid(),
                'reference' => $reference,
                'kind' => 'refund',
                'status' => 'refunded',
                'parent_reference' => $request->paymentReference,
                'amount' => $request->amount->minorUnits,
                'currency' => $request->amount->currency,
                'captured_amount' => 0,
                'refunded_amount' => 0,
                'customer_reference' => $payment->customer_reference,
                'idempotency_key' => $request->idempotencyKey,
                'description' => $request->reason,
                'metadata' => json_encode($request->metadata),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table(self::TABLE)
                ->where('reference', $request->paymentReference)
                ->update([
                    'refunded_amount' => (int) $payment->refunded_amount + $request->amount->minorUnits,
                    'updated_at' => now(),
                ]);

            return PaymentResult::refunded($reference, $request->amount, ['simulated' => true]);
        });
    }

    public function createPaymentLink(PaymentLinkRequest $request): PaymentResult
    {
        $reference = $this->reference('link');

        DB::table(self::TABLE)->insert([
            'id' => (string) Str::ulid(),
            'reference' => $reference,
            'kind' => 'authorization',
            'status' => 'pending',
            'amount' => $request->amount->minorUnits,
            'currency' => $request->amount->currency,
            'captured_amount' => 0,
            'refunded_amount' => 0,
            'customer_reference' => $request->customerEmail,
            'idempotency_key' => $request->idempotencyKey,
            'description' => $request->description,
            'metadata' => json_encode($request->metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The simulated checkout page lives inside the application so the
        // whole hosted-payment flow can be walked through locally.
        $url = rtrim((string) config('app.url'), '/').'/api/public/simulated-checkout/'.$reference;

        return PaymentResult::requiresAction($reference, $url, $request->amount, ['simulated' => true]);
    }

    public function storeInstrument(PaymentInstrument $instrument): PaymentResult
    {
        // A deterministic token derived from the setup token, so the same card
        // in a test always produces the same stored instrument.
        $token = 'pm_'.substr(hash('sha256', $instrument->setupToken), 0, 24);

        return PaymentResult::instrumentStored(
            $token,
            $this->last4($token),
            $this->brand($token),
            ['simulated' => true],
        );
    }

    public function parseWebhook(string $payload, array $headers, string $signingSecret): ?WebhookEnvelope
    {
        $signature = $this->headerValue($headers, 'x-simulated-signature');

        if ($signature === null) {
            return null;
        }

        $expected = hash_hmac('sha256', $payload, $signingSecret);

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode($payload, true);

        if (! is_array($data) || ! isset($data['type'], $data['id'])) {
            return null;
        }

        return new WebhookEnvelope(
            type: (string) $data['type'],
            providerEventId: (string) $data['id'],
            data: $data['data'] ?? [],
            occurredAt: isset($data['created_at'])
                ? new \DateTimeImmutable((string) $data['created_at'])
                : null,
        );
    }

    /**
     * Amounts ending in specific minor units force a scripted failure, which
     * is how the decline and 3-D Secure paths are covered by tests.
     */
    private function scriptedFailure(Money $amount): ?PaymentResult
    {
        $suffix = abs($amount->minorUnits) % 100;

        return match ($suffix) {
            self::DECLINE_SUFFIX => PaymentResult::failed(
                'card_declined',
                'The card was declined by the issuing bank.',
            ),
            self::INSUFFICIENT_FUNDS_SUFFIX => PaymentResult::failed(
                'insufficient_funds',
                'The card has insufficient funds.',
            ),
            self::REQUIRES_ACTION_SUFFIX => PaymentResult::requiresAction(
                $this->reference('act'),
                rtrim((string) config('app.url'), '/').'/api/public/simulated-authentication',
                $amount,
            ),
            default => null,
        };
    }

    private function findByIdempotencyKey(string $kind, string $key): ?object
    {
        return DB::table(self::TABLE)
            ->where('kind', $kind)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function reference(string $prefix): string
    {
        return $prefix.'_'.Str::lower((string) Str::ulid());
    }

    private function last4(?string $token): ?string
    {
        if ($token === null) {
            return null;
        }

        return substr(str_pad((string) hexdec(substr(hash('crc32b', $token), 0, 4)), 4, '0', STR_PAD_LEFT), -4);
    }

    private function brand(?string $token): ?string
    {
        if ($token === null) {
            return null;
        }

        $brands = ['visa', 'mastercard', 'amex', 'discover'];

        return $brands[hexdec(substr(hash('crc32b', $token), 0, 2)) % count($brands)];
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return is_array($value) ? ($value[0] ?? null) : (string) $value;
            }
        }

        return null;
    }
}
