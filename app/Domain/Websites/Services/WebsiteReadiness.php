<?php

declare(strict_types=1);

namespace App\Domain\Websites\Services;

use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Support\SitePayments;

/**
 * What a site still needs, line by line, as the Websites screen shows it.
 *
 * Only a site with nothing to show is kept from going live. Without payments,
 * or without email, a site still shows its homes and asks guests to get in
 * touch, as it was built to; without a contact address nobody hears of a new
 * booking; without image storage, uploaded images do not survive the next
 * deploy. Each line says what to set, and where.
 */
class WebsiteReadiness
{
    /** Each Render service reads its own settings: the scheduler releases holds and charges balances, the worker sends the email. */
    private const EVERY_SERVICE = 'habitat-api, habitat-worker and habitat-scheduler';

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
        $contact = $site->setting('contact_email');
        $contact = is_string($contact) && $contact !== '' ? $contact : null;

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
                'message' => match (true) {
                    $missing !== [] => sprintf(
                        'Guests are asked to get in touch until Stripe is set up. Missing: %s — set on %s.',
                        implode(', ', $missing),
                        self::EVERY_SERVICE,
                    ),
                    ! SitePayments::emailSent() => 'Stripe is set up, but guests are asked to get in touch until emails are sent (see Email).',
                    default => 'Stripe is set up: guests book and pay online.',
                },
                'blocks_publishing' => false,
            ],
            [
                'key' => 'email',
                'ok' => SitePayments::emailSent(),
                'message' => SitePayments::emailSent()
                    ? 'Guest and host emails are sent.'
                    : sprintf('Guest and host emails are only written to the log (MAIL_MAILER=%s). Set up an email provider on habitat-api and habitat-worker, which sends them.', $mailer),
                'blocks_publishing' => false,
            ],
            [
                'key' => 'contact',
                'ok' => $contact !== null,
                'message' => $contact !== null
                    ? sprintf('New bookings and requests are emailed to %s.', $contact)
                    : 'New bookings and requests are not emailed to anyone, and a request nobody answers is declined at its deadline. Add a contact email to the site.',
                'blocks_publishing' => false,
            ],
            [
                'key' => 'images',
                'ok' => config('filesystems.disks.media.driver') !== 'local',
                'message' => config('filesystems.disks.media.driver') === 'local'
                    ? 'Logos and photos uploaded here are kept on the server and lost at the next deploy. Set MEDIA_DISK=r2 and the R2 settings on habitat-api.'
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
