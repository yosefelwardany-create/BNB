<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\DataObjects\PaymentAuthorizationRequest;
use App\Domain\Integrations\DataObjects\PaymentCaptureRequest;
use App\Domain\Integrations\DataObjects\PaymentInstrument;
use App\Domain\Integrations\DataObjects\PaymentLinkRequest;
use App\Domain\Integrations\DataObjects\PaymentResult;
use App\Domain\Integrations\DataObjects\RefundRequest;
use App\Domain\Integrations\DataObjects\WebhookEnvelope;

/**
 * Everything the platform needs from a payment processor.
 *
 * Deliberately narrow: the platform owns the money model (schedules, ledger,
 * balances) and the provider only moves funds. Adding a new processor means
 * implementing this interface and registering it — no other part of the
 * product changes.
 *
 * Implementations must be idempotent with respect to
 * {@see PaymentAuthorizationRequest::$idempotencyKey}.
 */
interface PaymentProviderInterface
{
    /**
     * The registry key, e.g. "mock" or "stripe".
     */
    public function key(): string;

    public function displayName(): string;

    /**
     * Whether this implementation actually reaches an external processor.
     * The UI uses this to label a connection honestly: a simulated provider is
     * never shown as a live payment connection.
     */
    public function isLive(): bool;

    /**
     * Capabilities the implementation supports, e.g. ['authorize', 'capture',
     * 'partial_capture', 'refund', 'partial_refund', 'payment_link',
     * 'stored_instruments', '3ds'].
     *
     * @return list<string>
     */
    public function capabilities(): array;

    /**
     * Place a hold on funds without taking them.
     */
    public function authorize(PaymentAuthorizationRequest $request): PaymentResult;

    /**
     * Take funds from a previous authorization, or charge directly when the
     * provider does not separate the two steps.
     */
    public function capture(PaymentCaptureRequest $request): PaymentResult;

    /**
     * Release an authorization that will not be captured.
     */
    public function void(string $providerReference, ?string $idempotencyKey = null): PaymentResult;

    /**
     * Return funds. Providers must reject a refund that exceeds what was
     * captured; the platform also enforces this before calling.
     */
    public function refund(RefundRequest $request): PaymentResult;

    /**
     * Create a hosted page the guest can pay on, for flows where the platform
     * never touches card details.
     */
    public function createPaymentLink(PaymentLinkRequest $request): PaymentResult;

    /**
     * Store a payment instrument against a customer for later charges
     * (balance due, damages, upsells).
     */
    public function storeInstrument(PaymentInstrument $instrument): PaymentResult;

    /**
     * Verify the signature of an inbound webhook and normalise it.
     *
     * Returning null means the payload could not be authenticated and must be
     * discarded.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public function parseWebhook(string $payload, array $headers, string $signingSecret): ?WebhookEnvelope;
}
