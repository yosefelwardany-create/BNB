<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Websites\Models\WebsiteCheckout;
use App\Domain\Websites\Services\WebsitePayments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gives back the nights of website holds nobody paid for in time.
 *
 * Only the site's own checkouts are looked at. Other tentative bookings carry
 * a hold time too — a channel's pending request, a booking staff are still
 * arranging — and are not the site's to release.
 */
class ReleaseExpiredHolds extends Command
{
    protected $signature = 'websites:release-expired-holds';

    protected $description = 'Cancel website holds whose time to pay has passed';

    public function handle(WebsitePayments $payments): int
    {
        $released = 0;

        WebsiteCheckout::query()->lapsed()->with(['organization', 'website'])->lazyById(100)->each(
            function (WebsiteCheckout $checkout) use ($payments, &$released): void {
                // One that cannot be released is logged and retried next
                // minute; it must not keep the rest from being released.
                try {
                    // Stripe is asked first, so a payment its webhook has not
                    // reported yet confirms the booking instead of losing it.
                    $payments->lapse($checkout);
                    $released++;
                } catch (Throwable $e) {
                    Log::error('A website hold could not be released.', [
                        'website_checkout_id' => $checkout->getKey(),
                        'exception_type' => $e::class,
                        'error' => $e->getMessage(),
                    ]);
                }
            },
        );

        // Requests the host left unanswered are declined for them: the guest
        // is not kept waiting, and their money held, past the site's window.
        // One whose booking staff confirmed themselves is accepted instead.
        $declined = 0;

        WebsiteCheckout::query()->unanswered()->with(['organization', 'website'])->lazyById(100)->each(
            function (WebsiteCheckout $checkout) use ($payments, &$declined): void {
                try {
                    $payments->answerAtDeadline($checkout);
                    $declined++;
                } catch (Throwable $e) {
                    Log::error('An unanswered website request could not be declined.', [
                        'website_checkout_id' => $checkout->getKey(),
                        'exception_type' => $e::class,
                        'error' => $e->getMessage(),
                    ]);
                }
            },
        );

        $this->info(sprintf('Released %d hold(s); answered %d request(s) past their deadline.', $released, $declined));

        return self::SUCCESS;
    }
}
