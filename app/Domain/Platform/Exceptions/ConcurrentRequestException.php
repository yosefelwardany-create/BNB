<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Raised when a second request arrives for an idempotency key whose first
 * request is still running. Surfaces as HTTP 409 so the caller retries rather
 * than double-charging a guest.
 */
class ConcurrentRequestException extends HttpException
{
    public function __construct(string $message = 'A request with this idempotency key is already in progress.')
    {
        parent::__construct(409, $message);
    }
}
