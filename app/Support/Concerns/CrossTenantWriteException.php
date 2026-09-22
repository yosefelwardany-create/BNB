<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use RuntimeException;

/**
 * Raised when code attempts to persist a record that belongs to a different
 * organization than the one bound to the current execution context.
 */
final class CrossTenantWriteException extends RuntimeException {}
