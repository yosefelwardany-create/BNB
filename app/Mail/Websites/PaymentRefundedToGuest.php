<?php

declare(strict_types=1);

namespace App\Mail\Websites;

use App\Domain\Websites\Models\WebsiteCheckout;
use App\Domain\Websites\Support\SiteFormat;
use App\Support\Money\Money;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the site's contact address: a guest's payment arrived for a stay no
 * longer booked, and was refunded to them in full automatically.
 */
class PaymentRefundedToGuest extends WebsiteBookingMail
{
    public function __construct(WebsiteCheckout $checkout, protected readonly Money $amount, protected readonly string $why)
    {
        parent::__construct($checkout);
    }

    protected function subjectLine(): string
    {
        return sprintf('Payment refunded: %s', $this->listingTitle());
    }

    protected function template(): string
    {
        return 'payment-refunded-to-guest';
    }

    protected function extra(): array
    {
        return ['amount' => SiteFormat::money($this->amount), 'why' => $this->why];
    }

    public function envelope(): Envelope
    {
        return NewWebsiteBooking::toHost($this->checkout, $this->subjectLine());
    }
}
