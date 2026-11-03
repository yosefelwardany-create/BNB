<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Exceptions;

use RuntimeException;

/**
 * A call to a client's Shopify store that did not produce a usable answer.
 * `status` is the HTTP status where there was one; 401 and 403 mean the
 * store no longer accepts the platform's app.
 */
class ShopifyRequestException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
