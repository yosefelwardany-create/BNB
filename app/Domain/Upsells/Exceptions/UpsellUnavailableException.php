<?php

declare(strict_types=1);

namespace App\Domain\Upsells\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An extra that cannot be sold, or an order that cannot move.
 *
 * A 422 because every message here is something the caller can act on: order
 * it earlier, order fewer, pick another day. The message is written to be
 * shown to a guest rather than only to a developer, because on the guest
 * portal it will be.
 */
class UpsellUnavailableException extends HttpException
{
    public function __construct(string $message)
    {
        parent::__construct(422, $message);
    }
}
