<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payments\Models\PaymentSchedule;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Domain\Websites\Services\WebsitePayments;
use App\Mail\Websites\PayBalance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Asks guests who paid a deposit on a client's Shopify checkout to pay the
 * rest, once, when it falls due.
 *
 * A card payment's balance is taken from the saved card by itself; a Shopify
 * one has no card to take it from. The email links to the guest's booking
 * page, whose Pay balance makes the Shopify order for it.
 */
class RemindShopifyBalances extends Command
{
    protected $signature = 'websites:remind-shopify-balances';

    protected $description = 'Email guests whose balance on a Shopify-paid booking has fallen due';

    public function handle(WebsitePayments $payments, TenantContext $tenancy): int
    {
        $reminded = 0;

        WebsiteCheckout::query()
            ->where('payment_channel', WebsiteCheckout::CHANNEL_SHOPIFY)
            ->where('status', WebsiteCheckout::STATUS_CONFIRMED)
            ->whereNotNull('reservation_id')
            ->with(['website', 'organization'])
            ->lazyById(100)
            ->each(function (WebsiteCheckout $checkout) use ($payments, $tenancy, &$reminded): void {
                try {
                    $due = $payments->balanceDue($checkout);
                    $email = $checkout->guest['email'] ?? null;

                    if ($due === null || ! is_string($email) || $email === '') {
                        return;
                    }

                    // Each instalment is asked for once.
                    $unasked = $tenancy->withoutScope(fn () => PaymentSchedule::query()
                        ->where('reservation_id', $checkout->reservation_id)
                        ->where('organization_id', $checkout->organization_id)
                        ->whereIn('status', ['pending', 'partially_paid', 'failed', 'overdue'])
                        ->whereDate('due_on', '<=', now()->toDateString())
                        ->get()
                        ->filter(fn (PaymentSchedule $instalment): bool => ! isset($instalment->metadata['shopify_balance_asked_at'])));

                    if ($unasked->isEmpty()) {
                        return;
                    }

                    Mail::to($email)->queue(new PayBalance($checkout, $due['amount'], null));

                    $unasked->each(fn (PaymentSchedule $instalment) => $instalment->forceFill([
                        'metadata' => array_merge($instalment->metadata ?? [], ['shopify_balance_asked_at' => now()->toIso8601String()]),
                    ])->save());

                    $reminded++;
                } catch (Throwable $e) {
                    Log::error('A Shopify balance reminder could not be sent.', [
                        'website_checkout_id' => $checkout->getKey(),
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        $this->info(sprintf('Asked %d guest(s) to pay their balance on the store.', $reminded));

        return self::SUCCESS;
    }
}
