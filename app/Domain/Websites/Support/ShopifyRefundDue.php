<?php

declare(strict_types=1);

namespace App\Domain\Websites\Support;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Support\Money\Money;

/**
 * What staff still have to give back on the client's Shopify store for a
 * cancelled booking that was paid there: Habitat cannot refund it itself.
 *
 * The cancellation policy's refund, less what has been given back since the
 * booking was cancelled, and never more than the store's orders still hold.
 */
final class ShopifyRefundDue
{
    /**
     * @return array{amount: Money, order_name: string, order_url: string|null}|null
     */
    public static function for(Reservation $reservation): ?array
    {
        $checkout = $reservation->websiteCheckout;

        if ($checkout === null || $checkout->payment_channel !== WebsiteCheckout::CHANNEL_SHOPIFY || ! $reservation->status->isCancelled()) {
            return null;
        }

        $payments = Payment::query()->where('reservation_id', $reservation->getKey())
            ->where('method', 'shopify')
            ->orderBy('captured_at')
            ->get();

        if ($payments->isEmpty()) {
            return null;
        }

        $currency = $reservation->currency;
        $givenBack = (int) Refund::query()->where('reservation_id', $reservation->getKey())
            ->where('status', 'completed')
            ->when($reservation->cancelled_at !== null, fn ($query) => $query->where('completed_at', '>=', $reservation->cancelled_at))
            ->sum('amount');
        $held = $payments->sum(fn (Payment $payment): int => $payment->refundableAmount()->minorUnits);

        $due = min((int) $reservation->cancellation_refund - $givenBack, $held);

        if ($due <= 0) {
            return null;
        }

        $order = $payments->first(fn (Payment $payment): bool => $payment->refundableAmount()->isPositive());
        $orderId = $order?->metadata['shopify_order_id'] ?? null;
        $shop = $checkout->website?->shopifyConnection?->shop_domain;

        return [
            'amount' => Money::of($due, $currency),
            'order_name' => (string) $order?->provider_reference,
            'order_url' => is_string($shop) && is_string($orderId) && ctype_digit($orderId)
                ? sprintf('https://%s/admin/orders/%s', $shop, $orderId)
                : null,
        ];
    }
}
