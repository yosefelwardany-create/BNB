<?php

declare(strict_types=1);

namespace App\Mail\Websites;

use App\Domain\Websites\Models\WebsiteCheckout;
use App\Domain\Websites\Support\SiteFormat;
use App\Support\Money\Money;

/**
 * To the guest: their payment arrived for a stay that is no longer booked,
 * and has gone back to their card in full.
 */
class PaymentRefunded extends WebsiteBookingMail
{
    public function __construct(WebsiteCheckout $checkout, protected readonly Money $amount, protected readonly string $why)
    {
        parent::__construct($checkout);
    }

    protected function subjectLine(): string
    {
        return sprintf('Your payment for %s has been refunded', $this->listingTitle());
    }

    protected function template(): string
    {
        return 'payment-refunded';
    }

    protected function extra(): array
    {
        return ['amount' => SiteFormat::money($this->amount), 'why' => $this->why];
    }
}
