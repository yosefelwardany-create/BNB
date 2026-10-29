<?php

declare(strict_types=1);

namespace App\Domain\Websites\Jobs;

use App\Domain\Websites\Exceptions\CheckoutRefused;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Domain\Websites\Services\WebsitePayments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Gives a website request the answer staff gave its booking in Habitat:
 * confirming it accepts the request, cancelling it declines it.
 *
 * Queued, and only once the change to the booking is saved, so Stripe being
 * slow or down never stops staff changing a booking; tried again until
 * Stripe answers. A request answered in the meantime is left as it is.
 */
class AnswerWebsiteRequest implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const ACCEPT = 'accept';

    public const DECLINE = 'decline';

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(
        public readonly string $checkoutId,
        public readonly string $answer,
        public readonly ?string $reason = null,
        public readonly string $answeredBy = 'host',
    ) {}

    public function handle(WebsitePayments $payments): void
    {
        $checkout = WebsiteCheckout::query()->with(['organization', 'website'])->find($this->checkoutId);

        if ($checkout === null || $checkout->status !== WebsiteCheckout::STATUS_REQUESTED) {
            return;
        }

        try {
            $this->answer === self::ACCEPT
                ? $payments->accept($checkout)
                : $payments->decline($checkout, $this->reason, $this->answeredBy);
        } catch (CheckoutRefused) {
            // Answered another way since: by the host, the deadline or Stripe.
        }
    }
}
