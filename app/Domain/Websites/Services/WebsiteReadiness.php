<?php

declare(strict_types=1);

namespace App\Domain\Websites\Services;

use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Support\SitePayments;

/**
 * What a site still needs, line by line, as the Websites screen shows it.
 *
 * Only a site with nothing to show is kept from going live. Without payments
 * a site still shows its homes and asks guests to get in touch, as it was
 * built to; without email, guests are not written to; without image storage,
 * uploaded images do not survive the next deploy. Each line says what to set.
 */
class WebsiteReadiness
{
    /**
     * @return list<array{key: string, ok: bool, message: string, blocks_publishing: bool}>
     */
    public function check(Website $site): array
    {
        // A catalogue of its own each time: one kept by a long-lived
        // controller would remember the site's homes from an earlier request.
        $shown = app(WebsiteCatalogue::class)->listings($site)->count();
        $missing = array_keys(array_filter([
            'STRIPE_SECRET_KEY' => blank(config('services.stripe.secret')),
            'STRIPE_PUBLISHABLE_KEY' => blank(config('services.stripe.key')),
            'STRIPE_WEBHOOK_SECRET' => blank(config('services.stripe.webhook_secret')),
        ]));
        $mailer = (string) config('mail.default');

        return [
            [
                'key' => 'properties',
                'ok' => $shown > 0,
                'message' => $shown > 0
                    ? sprintf('%d %s shown.', $shown, $shown === 1 ? 'property is' : 'properties are')
                    : 'Add at least one property before publishing.',
                'blocks_publishing' => true,
            ],
            [
                'key' => 'payments',
                'ok' => SitePayments::online(),
                'message' => $missing === []
                    ? 'Stripe is set up: guests book and pay online.'
                    : 'Guests are asked to get in touch until Stripe is set up. Missing: '.implode(', ', $missing).'.',
                'blocks_publishing' => false,
            ],
            [
                'key' => 'email',
                'ok' => ! in_array($mailer, ['log', 'array'], true),
                'message' => in_array($mailer, ['log', 'array'], true)
                    ? sprintf('Guest and host emails are only written to the log (MAIL_MAILER=%s). Set up an email provider.', $mailer)
                    : 'Guest and host emails are sent.',
                'blocks_publishing' => false,
            ],
            [
                'key' => 'images',
                'ok' => config('filesystems.disks.media.driver') !== 'local',
                'message' => config('filesystems.disks.media.driver') === 'local'
                    ? 'Logos and photos uploaded here are kept on the server and lost at the next deploy. Set MEDIA_DISK=r2 and the R2 settings.'
                    : 'Uploaded logos and photos are kept in R2.',
                'blocks_publishing' => false,
            ],
        ];
    }

    /**
     * Why the site cannot go live yet, or null when it can.
     */
    public function publishingRefusal(Website $site): ?string
    {
        foreach ($this->check($site) as $line) {
            if ($line['blocks_publishing'] && ! $line['ok']) {
                return $line['message'];
            }
        }

        return null;
    }
}
