<?php

declare(strict_types=1);

namespace App\Domain\Websites\Services;

use App\Domain\Integrations\Exceptions\ShopifyRequestException;
use App\Domain\Integrations\Shopify\ShopifyClient;
use App\Domain\Websites\Exceptions\ShopifyInstallRefused;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteShopifyConnection;
use App\Domain\Websites\Support\SiteOrigin;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Connecting a client's Shopify store to a booking site.
 *
 * The platform owner asks for an install link; someone with admin rights on
 * the store approves the platform's app there; Shopify sends them back with
 * a code, which is exchanged for the store's token. The approval is trusted
 * only when Shopify's signature matches, it carries the state this link was
 * made with, it is used once, and within the hour.
 */
class ShopifyInstall
{
    public const SHOP_PATTERN = '/^[a-z0-9][a-z0-9-]*\.myshopify\.com\z/';

    private const STATE_MINUTES = 60;

    private const TOPICS = ['ORDERS_PAID', 'REFUNDS_CREATE', 'APP_UNINSTALLED'];

    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * The state is a random token with nothing in it to encode: the site and
     * the store it was made for are kept here, for an hour, and checked when
     * Shopify sends the approval back.
     */
    public function link(Website $site, string $shop): string
    {
        $state = Str::random(48);
        Cache::put('shopify-install:'.$state, ['w' => $site->getKey(), 's' => $shop], now()->addMinutes(self::STATE_MINUTES));
        // Kept longer, and never trusted: only where to send someone back to
        // when an approval comes late or twice.
        Cache::put('shopify-install-site:'.$state, $site->getKey(), now()->addDay());

        return $this->shopify->authorizeUrl($shop, $state);
    }

    /**
     * The site the approval belongs to, connected.
     *
     * @param  array<string, mixed>  $query  the callback's query string
     *
     * @throws ShopifyInstallRefused
     */
    public function complete(array $query): Website
    {
        // Checked before the state is used up, so a forged callback cannot
        // spend the real approval's one use.
        if (! $this->signed($query)) {
            throw new ShopifyInstallRefused('Shopify\'s signature did not match.', $this->websiteNamedBy($query['state'] ?? null));
        }

        $shop = $query['shop'] ?? null;

        if (! is_string($shop) || preg_match(self::SHOP_PATTERN, $shop) !== 1) {
            throw new ShopifyInstallRefused('That is not a Shopify store\'s address.', $this->websiteNamedBy($query['state'] ?? null));
        }

        // Approved on the store the link was made for, and nowhere else: a
        // link passed on cannot connect the site to somebody else's store.
        $site = $this->siteFromState($query['state'] ?? null, $shop);

        if ($site === null) {
            throw new ShopifyInstallRefused('The approval was for another store, used already, or came too late.', $this->websiteNamedBy($query['state'] ?? null));
        }

        $code = $query['code'] ?? null;

        if (! is_string($code) || $code === '') {
            throw new ShopifyInstallRefused('Shopify sent no code.', $site->getKey());
        }

        try {
            $token = $this->shopify->exchangeCode($shop, $code);

            $connection = WebsiteShopifyConnection::query()->firstOrNew(['website_id' => $site->getKey()]);
            $connection->forceFill([
                'shop_domain' => $shop,
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? null,
                'access_token_expires_at' => isset($token['expires_in']) ? now()->addSeconds($token['expires_in']) : null,
                'scopes' => $token['scope'],
                'status' => WebsiteShopifyConnection::STATUS_CONNECTED,
                'connected_at' => now(),
                'broken_at' => null,
                'last_error' => null,
            ]);

            // Saved only once the store has said who it is and agreed to
            // report its paid orders: a store that would take payments it
            // never reports is not connected.
            $this->describe($connection);
            $this->subscribe($connection);
            $connection->save();
        } catch (ShopifyRequestException $e) {
            Log::warning('A Shopify store could not be connected.', ['website_id' => $site->getKey(), 'shop' => $shop, 'error' => $e->getMessage()]);

            throw new ShopifyInstallRefused('Shopify did not answer as expected: '.$e->getMessage(), $site->getKey());
        }

        // The store's own addresses may show the site's pieces.
        $origins = array_values(array_unique([...$site->embedOrigins(), ...$connection->origins()]));
        $site->forceFill(['embed_origins' => array_slice($origins, 0, 10)])->save();

        return $site->fresh() ?? $site;
    }

    public function disconnect(Website $site): void
    {
        $site->shopifyConnection?->forceFill([
            'status' => WebsiteShopifyConnection::STATUS_DISCONNECTED,
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
        ])->save();
    }

    /**
     * The site a state names, read without using it up: where to send the
     * platform owner back to, whatever became of the approval.
     */
    public function websiteNamedBy(mixed $state): ?string
    {
        $site = is_string($state) && $state !== '' ? Cache::get('shopify-install-site:'.$state) : null;

        return is_string($site) ? $site : null;
    }

    /**
     * The site an install link was made for, if the state is one Habitat
     * made, within the hour, not used before, and for this store. Reading it
     * uses it up.
     */
    private function siteFromState(mixed $state, string $shop): ?Website
    {
        if (! is_string($state) || $state === '') {
            return null;
        }

        $entry = Cache::pull('shopify-install:'.$state);

        if (! is_array($entry) || ! is_string($entry['w'] ?? null) || ($entry['s'] ?? null) !== $shop) {
            return null;
        }

        return Website::query()->find($entry['w']);
    }

    /**
     * Shopify signs the callback's query with the app's secret: every
     * parameter but `hmac`, sorted, URL-encoded and joined with `&`, as
     * Shopify's own PHP library checks it.
     *
     * @param  array<string, mixed>  $query
     */
    private function signed(array $query): bool
    {
        $hmac = $query['hmac'] ?? null;

        if (! is_string($hmac) || $hmac === '') {
            return false;
        }

        $rest = array_filter($query, fn (mixed $value, string $key): bool => $key !== 'hmac' && $key !== 'signature' && is_string($value), ARRAY_FILTER_USE_BOTH);
        ksort($rest);

        return hash_equals(hash_hmac('sha256', http_build_query($rest), (string) config('services.shopify.client_secret')), $hmac);
    }

    private function describe(WebsiteShopifyConnection $connection): void
    {
        $shop = $this->shopify->graphql($connection, 'query { shop { name currencyCode myshopifyDomain primaryDomain { url } } }')['shop'] ?? [];

        $primary = is_string($shop['primaryDomain']['url'] ?? null) ? SiteOrigin::normalize($shop['primaryDomain']['url']) : null;

        $connection->forceFill([
            'shop_name' => is_string($shop['name'] ?? null) ? $shop['name'] : null,
            'currency' => is_string($shop['currencyCode'] ?? null) ? strtoupper($shop['currencyCode']) : null,
            'domains' => $primary !== null && $primary !== 'https://'.$connection->shop_domain ? [$primary] : [],
        ]);
    }

    /**
     * Ask the store to tell Habitat when an order is paid or refunded, and if
     * the app is removed. Connecting again finds them already there.
     */
    private function subscribe(WebsiteShopifyConnection $connection): void
    {
        foreach (self::TOPICS as $topic) {
            $answer = $this->shopify->graphql(
                $connection,
                'mutation ($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) { webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) { userErrors { field message } webhookSubscription { id } } }',
                ['topic' => $topic, 'webhookSubscription' => ['uri' => url('/webhooks/shopify'), 'format' => 'JSON']],
            );

            $errors = $answer['webhookSubscriptionCreate']['userErrors'] ?? [];
            $real = array_filter(is_array($errors) ? $errors : [], fn (mixed $error): bool => ! str_contains(strtolower((string) ($error['message'] ?? '')), 'already been taken'));

            if ($real !== []) {
                throw new ShopifyRequestException('Shopify would not send '.$topic.': '.(string) (array_values($real)[0]['message'] ?? 'refused'));
            }
        }
    }
}
