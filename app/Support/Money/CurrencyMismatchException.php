<?php

declare(strict_types=1);

namespace App\Support\Money;

use RuntimeException;

/**
 * Thrown whenever two amounts in different currencies are combined without an
 * explicit, recorded exchange rate. Silent currency coercion is the single
 * most common source of corrupted hospitality ledgers, so it is a hard error.
 */
final class CurrencyMismatchException extends RuntimeException {}
