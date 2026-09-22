<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Exceptions;

use RuntimeException;

/**
 * Raised when an AI capability is requested but no provider is enabled.
 *
 * Callers must handle this by telling the user AI assistance is off, never by
 * substituting a canned response — a guest-facing message that appears to be
 * model-written when it is not would be misleading.
 */
class AIProviderUnavailableException extends RuntimeException {}
