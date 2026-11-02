<?php

declare(strict_types=1);

namespace App\Domain\Websites\Services;

use App\Domain\Integrations\Shopify\ShopifyClient;
use App\Domain\Listings\Models\Listing;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteShopifyConnection;

/**
 * Bookings paid on a client's own Shopify checkout.
 *
 * A site takes them only while its store is connected and sells in the
 * currency its homes are priced in; otherwise its bookings stay on Stripe.
 */
class ShopifyCheckouts
{
    public function __construct(
        private readonly ShopifyClient $shopify,
        private readonly WebsiteCatalogue $catalogue,
    ) {}

    public function available(Website $site): bool
    {
        $connection = $site->shopifyConnection;

        return $this->shopify->configured()
            && $connection !== null
            && $connection->isConnected()
            && $this->currencyMatches($site, $connection);
    }

    /**
     * What the platform's screen shows of the site's store.
     *
     * @return array{configured: bool, status: string|null, shop_domain: string|null, shop_name: string|null, currency: string|null, currency_matches: bool, checkout_available: bool, last_error: string|null}
     */
    public function status(Website $site): array
    {
        $connection = $site->shopifyConnection;

        return [
            'configured' => $this->shopify->configured(),
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

    private function currencyMatches(Website $site, WebsiteShopifyConnection $connection): bool
    {
        $currencies = $this->siteCurrencies($site);

        return $currencies !== [] && $currencies === [strtoupper((string) $connection->currency)];
    }
}
