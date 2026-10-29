<?php

declare(strict_types=1);

namespace App\Domain\Websites\Support;

/**
 * Whether booking websites can take payment.
 *
 * Until the platform's Stripe keys are set, a site still shows prices but
 * asks the guest to get in touch rather than offering a booking it could not
 * be paid for.
 */
final class SitePayments
{
    public static function online(): bool
    {
        return filled(config('services.stripe.key')) && filled(config('services.stripe.secret'));
    }
}
