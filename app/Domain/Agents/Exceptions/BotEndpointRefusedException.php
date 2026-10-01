<?php

declare(strict_types=1);

namespace App\Domain\Agents\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Raised when a property's bot endpoint may not be called.
 *
 * 422 carrying the reason, because this always surfaces against a URL somebody
 * has just typed and the only useful answer names what is wrong with it.
 */
class BotEndpointRefusedException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(422, $message);
    }
}
