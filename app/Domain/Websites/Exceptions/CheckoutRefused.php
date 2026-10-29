<?php

declare(strict_types=1);

namespace App\Domain\Websites\Exceptions;

use RuntimeException;

/**
 * A checkout that cannot hold the stay, with the sentence the guest reads and
 * the status the page is answered with.
 */
final class CheckoutRefused extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function taken(): self
    {
        return new self('Those dates have just been booked by someone else.', 409);
    }

    public static function expired(): self
    {
        return new self('That price has expired. Check the dates again for a fresh one.', 409);
    }

    public static function spent(): self
    {
        return new self('That price has already been used for a booking. Check the dates again for a fresh one.', 409);
    }

    public static function priceChanged(): self
    {
        return new self('The price for these dates has just changed. Check the dates again for the new one.', 409);
    }

    public static function nothingDue(): self
    {
        return new self('Nothing is due on this booking.', 422);
    }

    public static function notWaiting(): self
    {
        return new self('This request is not waiting for an answer.', 422);
    }

    public static function authorisationLapsed(): self
    {
        return new self("The guest's card authorisation has lapsed, so nothing can be taken. Decline the request; the guest can book again.", 422);
    }

    public static function bookingCancelled(): self
    {
        return new self("This booking was cancelled in Habitat. Decline the request to release the guest's card.", 422);
    }

    public static function tooManyHolds(int $limit): self
    {
        return new self(sprintf(
            'You already have %d stays waiting for payment. Finish one, or wait for it to lapse, before holding another.',
            $limit,
        ), 429);
    }
}
