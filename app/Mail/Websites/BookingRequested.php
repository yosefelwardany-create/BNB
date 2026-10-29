<?php

declare(strict_types=1);

namespace App\Mail\Websites;

/**
 * To the guest: their request is with the host, who answers by its deadline.
 */
class BookingRequested extends WebsiteBookingMail
{
    protected function subjectLine(): string
    {
        return sprintf('Your request for %s is with the host', $this->listingTitle());
    }

    protected function template(): string
    {
        return 'booking-requested';
    }
}
