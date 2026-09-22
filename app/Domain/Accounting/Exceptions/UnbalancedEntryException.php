<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Exceptions;

use App\Domain\Accounting\DataObjects\JournalDraft;
use RuntimeException;

/**
 * An entry whose debits do not equal its credits.
 *
 * This is always a bug in the code that built the draft, never a user error,
 * so the message carries the whole draft. A ledger that accepted an unbalanced
 * entry would be wrong from that moment on in a way no later reconciliation
 * could detect, which is why this is a hard failure rather than a warning.
 */
class UnbalancedEntryException extends RuntimeException
{
    public function __construct(public readonly JournalDraft $draft)
    {
        parent::__construct(sprintf(
            "Journal entry does not balance: debits exceed credits by %d minor units.\n%s",
            $draft->imbalance(),
            $draft->describe(),
        ));
    }
}
