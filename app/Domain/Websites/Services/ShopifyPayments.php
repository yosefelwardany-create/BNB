<?php

declare(strict_types=1);

namespace App\Domain\Websites\Services;

use App\Domain\Websites\Models\WebsiteCheckout;
use App\Domain\Websites\Models\WebsiteShopifyConnection;
use App\Domain\Websites\Models\WebsiteShopifyOrder;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Log;

/**
 * What a client's Shopify store tells Habitat about the orders made for a
 * booking site's stays, and about the platform's app.
 *
 * A paid order is matched to its checkout by the attribute Habitat put on
 * it and to the store it was made on, and believed only for the amount and
 * currency Habitat asked for.
 */
class ShopifyPayments
{
    public function __construct(
        private readonly WebsitePayments $payments,
        private readonly ShopifyAlerts $alerts,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(string $topic, string $shop, array $payload): void
    {
        match ($topic) {
            'orders/paid' => $this->paid($shop, $payload),
            'refunds/create' => $this->refunded($shop, $payload),
            // The body is what Shopify signed; the header is not.
            'app/uninstalled' => $this->uninstalled(is_string($payload['myshopify_domain'] ?? null) ? strtolower($payload['myshopify_domain']) : $shop),
            // Shopify's privacy requests concern data Habitat does not keep
            // from Shopify.
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function paid(string $shop, array $order): void
    {
        $attributes = collect(is_array($order['note_attributes'] ?? null) ? $order['note_attributes'] : [])
            ->filter(fn (mixed $attribute): bool => is_array($attribute) && is_string($attribute['name'] ?? null))
            ->mapWithKeys(fn (array $attribute): array => [$attribute['name'] => (string) ($attribute['value'] ?? '')]);

        $checkoutId = $attributes->get('habitat_checkout');
        $purpose = $attributes->get('habitat_purpose', WebsiteShopifyOrder::PURPOSE_STAY);
        $orderName = (string) ($order['name'] ?? '');
        $orderId = (string) ($order['id'] ?? '');

        if (! is_string($checkoutId) || $checkoutId === '') {
            // Not one of Habitat's: an ordinary sale on the store.
            return;
        }

        $checkout = WebsiteCheckout::query()->with(['website', 'organization'])->find($checkoutId);
        $connection = $checkout === null ? null : WebsiteShopifyConnection::query()->where('website_id', $checkout->website_id)->first();

        if ($checkout === null || $connection === null || $connection->shop_domain !== $shop) {
            Log::warning('A paid Shopify order names a booking that is not this store\'s.', ['shop' => $shop, 'order' => $orderName, 'website_checkout_id' => $checkoutId]);

            return;
        }

        // The very order Habitat made, by the id it put on it; an order made
        // before that attribute existed, by its purpose.
        $mine = WebsiteShopifyOrder::query()
            ->where('website_checkout_id', $checkout->getKey())
            ->whereIn('status', [WebsiteShopifyOrder::STATUS_OPEN, WebsiteShopifyOrder::STATUS_DELETED])
            ->when(
                $attributes->has('habitat_order'),
                fn ($query) => $query->whereKey($attributes->get('habitat_order')),
                fn ($query) => $query->where('purpose', $purpose)->latest(),
            )
            ->lockForUpdate()
            ->first();
        $purpose = $mine?->purpose ?? $purpose;

        if ($mine === null) {
            // Already paid and recorded: Shopify said it twice.
            return;
        }

        $paid = self::amountOf($order);

        if ($paid === null || ! $paid->equals($mine->money())) {
            $mine->forceFill(['status' => WebsiteShopifyOrder::STATUS_FAILED, 'order_id' => $orderId, 'order_name' => $orderName])->save();
            $this->alerts->paymentNeedsAttention($checkout, $orderName, sprintf(
                'Shopify order %s was paid for %s, not the %s asked for. It is not confirmed: check it in Shopify.',
                $orderName,
                $paid === null ? 'an unreadable amount' : $paid->toDecimal().' '.$paid->currency,
                $mine->money()->toDecimal().' '.$mine->currency,
            ));

            Log::warning('A paid Shopify order does not match what was asked for.', ['website_checkout_id' => $checkout->getKey(), 'order' => $orderName]);

            return;
        }

        $mine->forceFill(['status' => WebsiteShopifyOrder::STATUS_PAID, 'order_id' => $orderId, 'order_name' => $orderName])->save();

        $purpose === WebsiteShopifyOrder::PURPOSE_BALANCE
            ? $this->payments->balancePaidOnShopify($checkout, $paid, $orderName, $orderId)
            : $this->payments->confirmPaidOnShopify($checkout, $paid, $orderName, $orderId);
    }

    /**
     * Money given back on the store, for an order Habitat made: recorded
     * against the booking's payment, once per Shopify refund. It does not
     * cancel the booking; cancelling is done in Habitat.
     *
     * @param  array<string, mixed>  $refund
     */
    private function refunded(string $shop, array $refund): void
    {
        $orderId = (string) ($refund['order_id'] ?? '');
        $refundId = (string) ($refund['id'] ?? '');

        if ($orderId === '' || $refundId === '') {
            return;
        }

        $order = WebsiteShopifyOrder::query()->with('checkout.organization')
            ->where('order_id', $orderId)
            ->where('status', WebsiteShopifyOrder::STATUS_PAID)
            ->first();

        if ($order === null || $order->checkout === null) {
            // Not one of Habitat's: an ordinary sale on the store.
            return;
        }

        $checkout = $order->checkout;
        $connection = WebsiteShopifyConnection::query()->where('website_id', $checkout->website_id)->first();

        if ($connection === null || $connection->shop_domain !== $shop) {
            Log::warning('A Shopify refund names an order that is not this store\'s.', ['shop' => $shop, 'order_id' => $orderId]);

            return;
        }

        $given = self::refundTransactions($refund);

        if ($given === []) {
            // Items restocked, no money moved.
            return;
        }

        $amount = self::sumOf($given);

        if ($amount === null) {
            Log::warning('A Shopify refund could not be read.', ['website_checkout_id' => $checkout->getKey(), 'order' => $order->order_name, 'refund_id' => $refundId]);

            return;
        }

        $this->payments->refundedOnShopify($checkout, $orderId, (string) $order->order_name, $amount, $refundId);
    }

    /**
     * The transactions that gave money back.
     *
     * @param  array<string, mixed>  $refund
     * @return list<array<string, mixed>>
     */
    private static function refundTransactions(array $refund): array
    {
        $transactions = is_array($refund['transactions'] ?? null) ? $refund['transactions'] : [];

        return array_values(array_filter(
            $transactions,
            fn (mixed $transaction): bool => is_array($transaction) && ($transaction['kind'] ?? null) === 'refund' && ($transaction['status'] ?? null) === 'success',
        ));
    }

    /**
     * What those transactions add up to, which Shopify sends in one
     * currency. Null when one cannot be read.
     *
     * @param  non-empty-list<array<string, mixed>>  $transactions
     */
    private static function sumOf(array $transactions): ?Money
    {
        $total = null;

        foreach ($transactions as $transaction) {
            $amount = $transaction['amount'] ?? null;
            $currency = $transaction['currency'] ?? null;

            if (! is_string($currency) || (! is_string($amount) && ! is_int($amount))) {
                return null;
            }

            try {
                $money = Money::fromDecimal($amount, strtoupper($currency));
            } catch (\InvalidArgumentException) {
                return null;
            }

            if ($total !== null && $total->currency !== $money->currency) {
                return null;
            }

            $total = $total === null ? $money : $total->add($money);
        }

        return $total;
    }

    /**
     * The client removed the platform's app: bookings stop going to their
     * store's checkout, and the token it gave is forgotten.
     */
    private function uninstalled(string $shop): void
    {
        WebsiteShopifyConnection::query()->where('shop_domain', $shop)->get()->each(
            fn (WebsiteShopifyConnection $connection) => $connection->forceFill([
                'status' => WebsiteShopifyConnection::STATUS_BROKEN,
                'broken_at' => now(),
                'last_error' => 'The app was removed from the store.',
                'access_token' => null,
                'refresh_token' => null,
                'access_token_expires_at' => null,
            ])->save(),
        );
    }

    /**
     * What an order was paid, as money: Shopify sends decimals as strings,
     * in as many places as the currency has (none for yen, three for dinar).
     *
     * @param  array<string, mixed>  $order
     */
    public static function amountOf(array $order): ?Money
    {
        $total = $order['current_total_price'] ?? $order['total_price'] ?? null;
        $currency = $order['currency'] ?? null;

        if (! is_string($currency) || (! is_string($total) && ! is_int($total))) {
            return null;
        }

        try {
            return Money::fromDecimal($total, strtoupper($currency));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
