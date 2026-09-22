<?php

declare(strict_types=1);

namespace App\Domain\Listings\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Raised when a listing cannot go on sale. The message names every blocker so
 * an operator can fix them in one pass rather than discovering them one at a
 * time through a channel's review queue.
 */
class ListingNotPublishableException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(422, $message);
    }
}
