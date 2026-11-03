<?php

declare(strict_types=1);

namespace App\Mail\Websites;

use App\Domain\Websites\Models\WebsiteCheckout;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the site's contact address: a guest paid on the client's Shopify store
 * and the booking could not simply be confirmed. Only someone with the
 * store can refund it, so it says which order.
 */
class ShopifyPaymentNeedsAttention extends WebsiteBookingMail
{
    public function __construct(WebsiteCheckout $checkout, public readonly string $orderName, public readonly string $why)
    {
        parent::__construct($checkout);
    }

    protected function subjectLine(): string
    {
        return sprintf('Shopify order %s needs attention', $this->orderName);
    }

    protected function template(): string
    {
        return 'shopify-payment-needs-attention';
    }

    protected function extra(): array
    {
        return ['orderName' => $this->orderName, 'why' => $this->why];
    }

    public function envelope(): Envelope
    {
        return NewWebsiteBooking::toHost($this->checkout, $this->subjectLine());
    }
}
