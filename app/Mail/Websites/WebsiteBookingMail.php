<?php

declare(strict_types=1);

namespace App\Mail\Websites;

use App\Domain\Websites\Models\WebsiteCheckout;
use App\Domain\Websites\Support\BookingSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An email about a website booking, sent from the queue.
 *
 * Carries only the checkout, which is not tenant-scoped, so the worker can
 * read it signed in nowhere; everything else is read when it is rendered. A
 * guest's reply goes to the site's contact address.
 */
abstract class WebsiteBookingMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly WebsiteCheckout $checkout)
    {
        // Sent once what it describes is saved, never for a change that was
        // rolled back.
        $this->afterCommit();
    }

    abstract protected function subjectLine(): string;

    /**
     * The home's title, read outside any account as the rest of the email is.
     */
    protected function listingTitle(): string
    {
        return (string) BookingSummary::for($this->checkout)['listing'];
    }

    abstract protected function template(): string;

    /**
     * @return array<string, mixed>
     */
    protected function extra(): array
    {
        return [];
    }

    public function envelope(): Envelope
    {
        $contact = $this->checkout->website?->setting('contact_email');

        return new Envelope(
            subject: $this->subjectLine(),
            replyTo: is_string($contact) && $contact !== '' ? [new Address($contact, $this->checkout->website?->name)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.websites.'.$this->template(),
            with: ['booking' => BookingSummary::for($this->checkout)] + $this->extra(),
        );
    }
}
