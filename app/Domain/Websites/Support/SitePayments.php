<?php

declare(strict_types=1);

namespace App\Domain\Websites\Support;

use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteShopifyConnection;
use App\Domain\Websites\Services\ShopifyCheckouts;

/**
 * Whether booking websites can take payment.
 *
 * Until the platform's Stripe keys and webhook secret are set, and email is
 * sent, a site still shows prices but asks the guest to get in touch rather
 * than offering a booking it could not be paid for, or would take money for
 * without telling anybody: no confirmation for the guest, no request for the
 * host to answer.
 */
final class SitePayments
{
    /**
     * Whether this site can take bookings online. Email is needed either way.
     *
     * A site connected to the client's Shopify store pays there and nowhere
     * else: while its store is broken, or sells in another currency, it takes
     * no bookings online rather than sending the client's guests' money to
     * the platform's Stripe. Any other site pays on Stripe.
     */
    public static function onlineFor(Website $site): bool
    {
        if (! self::emailSent()) {
            return false;
        }

        return self::onShopify($site)
            ? app(ShopifyCheckouts::class)->available($site)
            : self::stripeSetUp();
    }

    /**
     * Whether the site is bound to the client's Shopify checkout: connected,
     * or connected once and broken since. Disconnecting hands it back.
     */
    public static function onShopify(Website $site): bool
    {
        return in_array($site->shopifyConnection?->status, [
            WebsiteShopifyConnection::STATUS_CONNECTED,
            WebsiteShopifyConnection::STATUS_BROKEN,
        ], true);
    }

    public static function online(): bool
    {
        // The webhook secret too: without it no payment is ever confirmed.
        return self::stripeSetUp() && self::emailSent();
    }

    public static function stripeSetUp(): bool
    {
        return filled(config('services.stripe.key'))
            && filled(config('services.stripe.secret'))
            && filled(config('services.stripe.webhook_secret'));
    }

    /**
     * Whether email leaves Habitat at all. `log`, Render's placeholder, only
     * writes it to the log.
     */
    public static function emailSent(): bool
    {
        return ! in_array((string) config('mail.default'), ['', 'log'], true);
    }
}
