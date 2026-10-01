<?php

declare(strict_types=1);

namespace App\Domain\Agents\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Raised when a property is asked to do something its agent is not set up for.
 *
 * 422 rather than 404 or 500: the request is well formed, the property exists,
 * and the thing missing is a setting whoever sent it can go and fill in. The
 * message says which one.
 */
class AgentNotConfiguredException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(422, $message);
    }
}
