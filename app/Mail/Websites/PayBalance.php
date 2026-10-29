<?php

declare(strict_types=1);

namespace App\Mail\Websites;

use App\Domain\Websites\Models\WebsiteCheckout;
use App\Domain\Websites\Support\SiteFormat;
use App\Support\Money\Money;

/**
 * To the guest: an instalment could not be taken from their saved card, and
 * where to pay it themselves.
 */
class PayBalance extends WebsiteBookingMail
{
    public function __construct(WebsiteCheckout $checkout, protected readonly Money $amount, protected readonly ?string $why = null)
    {
        parent::__construct($checkout);
    }

    protected function subjectLine(): string
    {
        return sprintf('Payment needed for your stay at %s', $this->listingTitle());
    }

    protected function template(): string
    {
        return 'pay-balance';
    }

    protected function extra(): array
    {
        return ['amount' => SiteFormat::money($this->amount), 'why' => $this->why];
    }
}
