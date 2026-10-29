<?php

declare(strict_types=1);

namespace App\Mail\Websites;

/**
 * To the site's contact address: a new booking, or a request to answer
 * before its deadline.
 */
class NewWebsiteBooking extends WebsiteBookingMail
{
    protected function subjectLine(): string
    {
        $listing = $this->listingTitle();

        return $this->checkout->mode === 'request'
            ? sprintf('New request to book %s', $listing)
            : sprintf('New booking: %s', $listing);
    }

    protected function template(): string
    {
        return 'new-website-booking';
    }
}
