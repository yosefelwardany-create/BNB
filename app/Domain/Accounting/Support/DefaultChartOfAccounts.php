<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Support;

use App\Domain\Accounting\Enums\AccountType;

/**
 * The chart of accounts a new organization starts with.
 *
 * It is deliberately shaped around short-term rental management rather than
 * generic bookkeeping: the accounts that matter here are the ones that keep
 * guest money, owner money and management-company money separate.
 *
 * `system_key` marks accounts the application posts to by name. Those accounts
 * can be renamed by the customer but not deleted, because the posting rules
 * resolve them through this key.
 */
final class DefaultChartOfAccounts
{
    // Accounts the posting engine resolves by key.
    public const CASH = 'cash';

    public const CLEARING = 'payment_clearing';

    public const ACCOUNTS_RECEIVABLE = 'accounts_receivable';

    public const GUEST_DEPOSITS = 'guest_deposits';

    public const DEFERRED_REVENUE = 'deferred_revenue';

    public const TAX_PAYABLE = 'tax_payable';

    public const OWNER_PAYABLE = 'owner_payable';

    public const ACCOUNTS_PAYABLE = 'accounts_payable';

    public const SECURITY_DEPOSITS_HELD = 'security_deposits_held';

    public const ACCOMMODATION_REVENUE = 'accommodation_revenue';

    public const CLEANING_REVENUE = 'cleaning_fee_revenue';

    public const FEE_REVENUE = 'other_fee_revenue';

    public const UPSELL_REVENUE = 'upsell_revenue';

    public const MANAGEMENT_FEE_REVENUE = 'management_fee_revenue';

    public const CHANNEL_COMMISSION = 'channel_commission_expense';

    public const PAYMENT_PROCESSING = 'payment_processing_expense';

    public const CLEANING_EXPENSE = 'cleaning_expense';

    public const MAINTENANCE_EXPENSE = 'maintenance_expense';

    public const SUPPLIES_EXPENSE = 'supplies_expense';

    public const UTILITIES_EXPENSE = 'utilities_expense';

    public const REFUNDS = 'refunds_and_allowances';

    public const OWNER_CONTRIBUTIONS = 'owner_contributions';

    public const RETAINED_EARNINGS = 'retained_earnings';

    public const FX_GAIN_LOSS = 'fx_gain_loss';

    /**
     * @return list<array{code: string, name: string, type: AccountType, system_key: ?string, description: string}>
     */
    public static function accounts(): array
    {
        return [
            // ----- Assets -------------------------------------------------
            self::account('1000', 'Operating bank account', AccountType::Asset, self::CASH,
                'Money actually held by the management company.'),
            self::account('1010', 'Payment processor clearing', AccountType::Asset, self::CLEARING,
                'Captured card payments not yet settled into the bank account.'),
            self::account('1100', 'Accounts receivable', AccountType::Asset, self::ACCOUNTS_RECEIVABLE,
                'Amounts invoiced to guests, owners or channels and not yet paid.'),

            // ----- Liabilities --------------------------------------------
            self::account('2000', 'Guest deposits held', AccountType::Liability, self::GUEST_DEPOSITS,
                'Prepayments received for stays that have not yet begun.'),
            self::account('2010', 'Deferred accommodation revenue', AccountType::Liability, self::DEFERRED_REVENUE,
                'Booked accommodation value not yet earned; recognised night by night.'),
            self::account('2020', 'Security deposits held', AccountType::Liability, self::SECURITY_DEPOSITS_HELD,
                'Refundable damage deposits. Never revenue.'),
            self::account('2100', 'Lodging tax payable', AccountType::Liability, self::TAX_PAYABLE,
                'Taxes collected from guests and owed to authorities.'),
            self::account('2200', 'Owner payable', AccountType::Liability, self::OWNER_PAYABLE,
                'Net amounts owed to property owners. The balance of this account is what owner statements settle.'),
            self::account('2300', 'Accounts payable', AccountType::Liability, self::ACCOUNTS_PAYABLE,
                'Amounts owed to vendors and contractors.'),

            // ----- Equity -------------------------------------------------
            self::account('3000', 'Owner contributions', AccountType::Equity, self::OWNER_CONTRIBUTIONS,
                'Funds contributed by owners to cover expenses.'),
            self::account('3900', 'Retained earnings', AccountType::Equity, self::RETAINED_EARNINGS,
                'Accumulated result of prior periods.'),

            // ----- Revenue ------------------------------------------------
            self::account('4000', 'Accommodation revenue', AccountType::Revenue, self::ACCOMMODATION_REVENUE,
                'Nightly rate revenue, recognised as each night is stayed.'),
            self::account('4100', 'Cleaning fee revenue', AccountType::Revenue, self::CLEANING_REVENUE,
                'Cleaning fees charged to guests.'),
            self::account('4200', 'Other fee revenue', AccountType::Revenue, self::FEE_REVENUE,
                'Pet fees, extra guest fees, resort fees and similar.'),
            self::account('4300', 'Upsell revenue', AccountType::Revenue, self::UPSELL_REVENUE,
                'Early check-in, late checkout, transfers and other add-ons.'),
            self::account('4400', 'Management fee revenue', AccountType::Revenue, self::MANAGEMENT_FEE_REVENUE,
                'Commission earned by the management company from owners.'),
            self::account('4900', 'Refunds and allowances', AccountType::Revenue, self::REFUNDS,
                'Contra-revenue for refunds and goodwill credits.'),

            // ----- Expenses -----------------------------------------------
            self::account('5000', 'Channel commission', AccountType::Expense, self::CHANNEL_COMMISSION,
                'Commission retained by OTAs.'),
            self::account('5010', 'Payment processing fees', AccountType::Expense, self::PAYMENT_PROCESSING,
                'Card and bank processing costs.'),
            self::account('5100', 'Cleaning costs', AccountType::Expense, self::CLEANING_EXPENSE,
                'Amounts paid to cleaners and cleaning companies.'),
            self::account('5200', 'Maintenance and repairs', AccountType::Expense, self::MAINTENANCE_EXPENSE,
                'Repairs, parts and contractor labour.'),
            self::account('5300', 'Supplies and consumables', AccountType::Expense, self::SUPPLIES_EXPENSE,
                'Linen, toiletries, welcome packs and restocking.'),
            self::account('5400', 'Utilities', AccountType::Expense, self::UTILITIES_EXPENSE,
                'Electricity, water, internet and similar recurring property costs.'),
            self::account('5900', 'Foreign exchange gain / loss', AccountType::Expense, self::FX_GAIN_LOSS,
                'Difference arising when a transaction settles at a different rate than booked.'),
        ];
    }

    /**
     * @return array{code: string, name: string, type: AccountType, system_key: ?string, description: string}
     */
    private static function account(
        string $code,
        string $name,
        AccountType $type,
        ?string $systemKey,
        string $description,
    ): array {
        return compact('code', 'name', 'type', 'description') + ['system_key' => $systemKey];
    }
}
