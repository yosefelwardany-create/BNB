<?php

declare(strict_types=1);

namespace App\Mail\Websites;

use App\Domain\Websites\Models\WebsiteCheckout;

/**
 * To the guest: the host could not accept their request, or did not answer
 * in time. Nothing was charged.
 */
class RequestDeclined extends WebsiteBookingMail
{
    public function __construct(WebsiteCheckout $checkout, protected readonly ?string $reason = null)
    {
        parent::__construct($checkout);
    }

    protected function subjectLine(): string
    {
        return sprintf('Your request for %s was not accepted', $this->listingTitle());
    }

    protected function template(): string
    {
        return 'request-declined';
    }

    protected function extra(): array
    {
        return ['reason' => $this->reason];
    }
}
