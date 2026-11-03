<?php

declare(strict_types=1);

namespace App\Domain\Websites\Exceptions;

use RuntimeException;

/**
 * Shopify's answer to an install that cannot be trusted or completed: a
 * signature that does not match, an approval used twice or too late, a store
 * address that is not a Shopify one, or a store that would not answer.
 */
class ShopifyInstallRefused extends RuntimeException
{
    public function __construct(string $reason, public readonly ?string $websiteId = null)
    {
        parent::__construct($reason);
    }
}
