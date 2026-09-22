<?php

declare(strict_types=1);

namespace App\Domain\Properties\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Raised when a change to a property would break something that already
 * depends on it — an upcoming reservation, or historical amounts recorded in
 * its currency.
 *
 * Surfaces as HTTP 422 so the caller sees the reason rather than a generic
 * failure.
 */
class PropertyInUseException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(422, $message);
    }
}
