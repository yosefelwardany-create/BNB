<?php

declare(strict_types=1);

namespace App\Mail\Websites;

use App\Domain\Websites\Models\WebsiteCheckout;
use DateTimeInterface;

/**
 * To the guest: the host accepted their request, and they pay on the
 * client's own store to confirm it, by the time the nights are held for.
 */
class RequestAcceptedPayOnStore extends WebsiteBookingMail
{
    public function __construct(
        WebsiteCheckout $checkout,
        public readonly string $payUrl,
        public readonly DateTimeInterface $payBy,
    ) {
        parent::__construct($checkout);
    }

    protected function subjectLine(): string
    {
        return sprintf('Your request for %s is accepted: pay to confirm it', $this->listingTitle());
    }

    protected function template(): string
    {
        return 'request-accepted-pay';
    }

    protected function extra(): array
    {
        return [
            'payUrl' => $this->payUrl,
            'payBy' => $this->payBy->format('j F Y, H:i'),
        ];
    }
}
