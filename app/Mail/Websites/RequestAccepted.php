<?php

declare(strict_types=1);

namespace App\Mail\Websites;

/**
 * To the guest: the host accepted their request, and the stay is booked.
 */
class RequestAccepted extends WebsiteBookingMail
{
    protected function subjectLine(): string
    {
        return sprintf('Your request for %s is accepted', $this->listingTitle());
    }

    protected function template(): string
    {
        return 'request-accepted';
    }
}
