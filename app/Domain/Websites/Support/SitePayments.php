<?php

declare(strict_types=1);

namespace App\Domain\Websites\Support;

use App\Domain\Websites\Models\Website;
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
     * Whether this site can take bookings online: on the platform's Stripe,
     * or on the client's own Shopify checkout when the site is connected to
     * a store selling in its homes' currency. Email is needed either way.
     */
    public static function onlineFor(Website $site): bool
    {
        return self::emailSent() && (self::stripeSetUp() || app(ShopifyCheckouts::class)->available($site));
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
