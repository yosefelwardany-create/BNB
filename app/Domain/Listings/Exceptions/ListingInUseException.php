<?php

declare(strict_types=1);

namespace App\Domain\Listings\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Raised when taking a listing off the books would break something that depends
 * on it still being there.
 *
 * Surfaces as HTTP 422 carrying the reason, because "no" without a reason on a
 * button somebody has just pressed is indistinguishable from a fault.
 */
class ListingInUseException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(422, $message);
    }
}
