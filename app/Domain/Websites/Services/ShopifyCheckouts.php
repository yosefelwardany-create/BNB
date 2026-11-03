<?php

declare(strict_types=1);

namespace App\Domain\Websites\Services;

use App\Domain\Integrations\Exceptions\ShopifyRequestException;
use App\Domain\Integrations\Shopify\ShopifyClient;
use App\Domain\Listings\Models\Listing;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Domain\Websites\Models\WebsiteShopifyConnection;
use App\Domain\Websites\Models\WebsiteShopifyOrder;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Bookings paid on a client's own Shopify checkout.
 *
 * A site takes them only while its store is connected and sells in the
 * currency its homes are priced in; otherwise its bookings stay on Stripe.
 */
class ShopifyCheckouts
{
    public const TAG = 'Habitat booking';

    public function __construct(
        private readonly ShopifyClient $shopify,
        private readonly WebsiteCatalogue $catalogue,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * The Shopify order a guest pays this checkout's stay (or its balance)
     * with: one line at the amount Habitat worked out, no tax added and
     * nothing to ship. Asked for twice, the same open order.
     *
     * @throws ShopifyRequestException when the store will not make it
     */
    public function start(WebsiteCheckout $checkout, string $purpose, Money $amount): WebsiteShopifyOrder
    {
        $open = WebsiteShopifyOrder::query()
            ->where('website_checkout_id', $checkout->getKey())
            ->where('purpose', $purpose)
            ->where('status', WebsiteShopifyOrder::STATUS_OPEN)
            ->where('amount', $amount->minorUnits)
            ->where('currency', $amount->currency)
            ->first();

        if ($open !== null) {
            return $open;
        }

        $connection = $this->connection($checkout);
        // Made before Shopify is asked, so the order carries it back.
        $id = (string) Str::ulid();
        $reservation = $this->tenancy->withoutScope(fn (): ?Reservation => $checkout->reservation_id === null ? null : Reservation::query()->with('listing')->find($checkout->reservation_id));

        $answer = $this->shopify->graphql(
            $connection,
            'mutation ($input: DraftOrderInput!) { draftOrderCreate(input: $input) { draftOrder { id name invoiceUrl } userErrors { field message } } }',
            ['input' => [
                'email' => (string) ($checkout->guest['email'] ?? ''),
                'note' => 'Booking '.($reservation?->confirmation_code ?? $checkout->getKey()),
                'tags' => [self::TAG],
                // The price already holds every fee and tax Habitat charges.
                'taxExempt' => true,
                'customAttributes' => [
                    ['key' => 'habitat_checkout', 'value' => $checkout->getKey()],
                    ['key' => 'habitat_purpose', 'value' => $purpose],
                    ['key' => 'habitat_order', 'value' => $id],
                ],
                'lineItems' => [[
                    'title' => $this->title($reservation, $purpose),
                    'quantity' => 1,
                    'originalUnitPriceWithCurrency' => ['amount' => $amount->toDecimal(), 'currencyCode' => $amount->currency],
                    'taxable' => false,
                    'requiresShipping' => false,
                ]],
            ]],
        );

        $created = $answer['draftOrderCreate'] ?? [];
        $errors = $created['userErrors'] ?? [];
        $draft = $created['draftOrder'] ?? null;

        if ((is_array($errors) && $errors !== []) || ! is_array($draft) || ! is_string($draft['invoiceUrl'] ?? null)) {
            throw new ShopifyRequestException('Shopify would not make the order: '.(string) ($errors[0]['message'] ?? 'no checkout link'));
        }

        $order = new WebsiteShopifyOrder;
        $order->id = $id;

        return tap($order->fill([
            'website_checkout_id' => $checkout->getKey(),
            'purpose' => $purpose,
            'draft_order_id' => (string) $draft['id'],
            'draft_order_name' => is_string($draft['name'] ?? null) ? $draft['name'] : null,
            'invoice_url' => $draft['invoiceUrl'],
            'amount' => $amount->minorUnits,
            'currency' => $amount->currency,
            'status' => WebsiteShopifyOrder::STATUS_OPEN,
        ]))->save();
    }

    /**
     * Take an unpaid order back, so its checkout link stops working.
     *
     * @throws ShopifyRequestException
     */
    public function delete(WebsiteShopifyOrder $order): void
    {
        if ($order->status !== WebsiteShopifyOrder::STATUS_OPEN) {
            return;
        }

        $checkout = $order->checkout()->with('website')->firstOrFail();

        $answer = $this->shopify->graphql(
            $this->connection($checkout),
            'mutation ($input: DraftOrderDeleteInput!) { draftOrderDelete(input: $input) { deletedId userErrors { field message } } }',
            ['input' => ['id' => $order->draft_order_id]],
        );

        $errors = $answer['draftOrderDelete']['userErrors'] ?? [];

        // Already gone (paid, or deleted in Shopify) is as good as deleted.
        if (is_array($errors) && $errors !== [] && ! str_contains(strtolower((string) ($errors[0]['message'] ?? '')), 'not exist')) {
            throw new ShopifyRequestException('Shopify would not delete the order: '.(string) ($errors[0]['message'] ?? 'refused'));
        }

        $order->forceFill(['status' => WebsiteShopifyOrder::STATUS_DELETED])->save();
    }

    /**
     * The open order a guest still has to pay, if any: what their booking
     * page sends them to.
     */
    public function openOrder(WebsiteCheckout $checkout, string $purpose = WebsiteShopifyOrder::PURPOSE_STAY): ?WebsiteShopifyOrder
    {
        return WebsiteShopifyOrder::query()
            ->where('website_checkout_id', $checkout->getKey())
            ->where('purpose', $purpose)
            ->where('status', WebsiteShopifyOrder::STATUS_OPEN)
            ->latest()
            ->first();
    }

    public function available(Website $site): bool
    {
        $connection = $site->shopifyConnection;

        return $connection !== null
            && $connection->isConnected()
            && $this->currencyMatches($site, $connection);
    }

    /**
     * What the platform's screen shows of the site's store.
     *
     * @return array{has_keys: bool, client_id: string|null, status: string|null, shop_domain: string|null, shop_name: string|null, currency: string|null, currency_matches: bool, checkout_available: bool, last_error: string|null}
     */
    public function status(Website $site): array
    {
        $connection = $site->shopifyConnection;

        return [
            // The store's own app: its Client ID, and whether its secret is kept.
            'has_keys' => $connection?->hasKeys() ?? false,
            'client_id' => $connection?->client_id,
            'status' => $connection?->status,
            'shop_domain' => $connection?->shop_domain,
            'shop_name' => $connection?->shop_name,
            'currency' => $connection?->currency,
            'currency_matches' => $connection !== null && $this->currencyMatches($site, $connection),
            'checkout_available' => $this->available($site),
            'last_error' => $connection?->status === WebsiteShopifyConnection::STATUS_BROKEN ? $connection->last_error : null,
        ];
    }

    /**
     * The currencies the site's homes are priced in, which a Shopify order
     * for one of its stays must be in too.
     *
     * @return list<string>
     */
    public function siteCurrencies(Website $site): array
    {
        return $this->catalogue->listings($site)
            ->map(fn (Listing $listing): string => strtoupper((string) $listing->currency))
            ->unique()
            ->values()
            ->all();
    }

    private function connection(WebsiteCheckout $checkout): WebsiteShopifyConnection
    {
        $connection = WebsiteShopifyConnection::query()->where('website_id', $checkout->website_id)->first();

        if ($connection === null || ! $connection->isConnected()) {
            throw new ShopifyRequestException('The site\'s Shopify store is not connected.');
        }

        return $connection;
    }

    /**
     * What the order's one line says: the home, the nights and the guests,
     * so the client recognises it among the store's orders.
     */
    private function title(?Reservation $reservation, string $purpose): string
    {
        if ($reservation === null) {
            return 'Stay';
        }

        $guests = (int) $reservation->adults + (int) $reservation->children;
        $stay = sprintf(
            '%s · %s–%s · %d %s',
            $reservation->listing?->displayTitle() ?? 'Stay',
            $reservation->check_in_date->format('j M'),
            $reservation->check_out_date->format('j M Y'),
            $guests,
            $guests === 1 ? 'guest' : 'guests',
        );

        return $purpose === WebsiteShopifyOrder::PURPOSE_BALANCE ? 'Balance · '.$stay : $stay;
    }

    private function currencyMatches(Website $site, WebsiteShopifyConnection $connection): bool
    {
        $currencies = $this->siteCurrencies($site);

        return $currencies !== [] && $currencies === [strtoupper((string) $connection->currency)];
    }
}
