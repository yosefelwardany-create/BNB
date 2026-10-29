<?php

declare(strict_types=1);

namespace App\Mail\Websites;

/**
 * To the guest: their stay is booked and paid for as agreed.
 */
class BookingConfirmed extends WebsiteBookingMail
{
    protected function subjectLine(): string
    {
        return sprintf('Your stay at %s is booked', $this->listingTitle());
    }

    protected function template(): string
    {
        return 'booking-confirmed';
    }
}
