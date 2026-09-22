<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Raised when tenant-scoped work is attempted without an organization bound to
 * the execution context. Failing loudly is deliberate: the alternative is
 * leaking one customer's data into another customer's response.
 */
final class TenantNotResolvedException extends RuntimeException {}
