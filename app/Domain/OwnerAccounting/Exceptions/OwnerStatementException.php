<?php

declare(strict_types=1);

namespace App\Domain\OwnerAccounting\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A statement or payout was asked to do something that would misstate an
 * owner's account.
 *
 * A 422 rather than a 500: every one of these is a refusal the caller can
 * understand and act on — approve the statement first, cancel the failed
 * payout, wait for the period to close.
 */
class OwnerStatementException extends HttpException
{
    public function __construct(string $message, public readonly ?string $reason = null)
    {
        parent::__construct(422, $message);
    }
}
