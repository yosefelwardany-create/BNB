<?php

declare(strict_types=1);

namespace App\Domain\Payments\Events;

use App\Domain\Payments\Models\Payment;

/**
 * The shape payment events publish.
 *
 * Deliberately excludes the instrument token. It is the one field here that
 * can be used to charge somebody, and an event stream is read by automation,
 * webhooks and analytics — none of which need it.
 */
final class PaymentPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(Payment $payment): array
    {
        return [
            'payment_id' => $payment->getKey(),
            'reference' => $payment->reference,
            'kind' => $payment->kind->value,
            'status' => $payment->status->value,
            'amount' => $payment->amount,
            'captured_amount' => $payment->captured_amount,
            'refunded_amount' => $payment->refunded_amount,
            'fee_amount' => $payment->fee_amount,
            'currency' => $payment->currency,
            'method' => $payment->method,
            'provider' => $payment->provider,

            // Whether anything real happened. A simulated provider is reported
            // as one everywhere, including here.
            'is_simulated' => (bool) $payment->is_simulated,
            'is_collected_by_us' => (bool) $payment->is_collected_by_us,

            'reservation_id' => $payment->reservation_id,
            'guest_id' => $payment->guest_id,
            'property_id' => $payment->property_id,
            'instrument_brand' => $payment->instrument_brand,
            'instrument_last4' => $payment->instrument_last4,
        ];
    }
}
