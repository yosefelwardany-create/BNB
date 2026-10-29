<?php

declare(strict_types=1);

namespace App\Mail\Websites;

use App\Domain\Websites\Models\WebsiteCheckout;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the site's contact address: a new booking, or a request to answer
 * before its deadline. The host's reply goes straight to the guest.
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

    public function envelope(): Envelope
    {
        return self::toHost($this->checkout, $this->subjectLine());
    }

    /**
     * An email to the host about a guest, answered by writing to the guest.
     */
    public static function toHost(WebsiteCheckout $checkout, string $subject): Envelope
    {
        $email = $checkout->guest['email'] ?? null;
        $name = trim(($checkout->guest['first_name'] ?? '').' '.($checkout->guest['last_name'] ?? ''));

        return new Envelope(
            subject: $subject,
            replyTo: is_string($email) && $email !== '' ? [new Address($email, $name !== '' ? $name : null)] : [],
        );
    }
}
