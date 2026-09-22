<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Enums;

/**
 * The five classical account types.
 *
 * `normalBalance()` is what makes the ledger self-checking: a debit increases
 * an asset or expense and decreases a liability, equity or revenue account.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    /**
     * 'debit' or 'credit'.
     */
    public function normalBalance(): string
    {
        return match ($this) {
            self::Asset, self::Expense => 'debit',
            self::Liability, self::Equity, self::Revenue => 'credit',
        };
    }

    /**
     * Whether a debit increases the balance of accounts of this type.
     */
    public function debitIncreases(): bool
    {
        return $this->normalBalance() === 'debit';
    }

    /**
     * Balance sheet accounts carry forward between periods; profit and loss
     * accounts are closed into equity.
     */
    public function isBalanceSheet(): bool
    {
        return in_array($this, [self::Asset, self::Liability, self::Equity], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
