<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\DataObjects\JournalDraft;
use App\Domain\Accounting\Exceptions\UnbalancedEntryException;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Platform\Services\SequenceGenerator;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes to the ledger.
 *
 * Every financial fact in the product arrives here: a booking confirmed, a card
 * captured, an expense recorded, an owner paid. Nothing else writes journal
 * lines, which is what makes "does the ledger agree with the reservations
 * table?" a question with a single place to look.
 *
 * Three rules are absolute.
 *
 * **An entry must balance.** Debits equal credits or nothing is written. An
 * unbalanced entry is a bug in the caller, and a ledger that accepted one would
 * be wrong from that moment in a way no later reconciliation could detect.
 *
 * **A posted entry is immutable.** Corrections are made by posting a reversing
 * entry that points back at the original. Editing history destroys the one
 * property that makes a ledger worth keeping — that it records what was
 * believed at the time, not what is believed now.
 *
 * **Accounts are resolved by system key, not by code.** Customers renumber
 * their chart of accounts to match their accountant's conventions, and the
 * posting rules must survive that.
 */
class JournalPoster
{
    /** @var array<string, LedgerAccount> Resolved accounts, per organization. */
    private array $accounts = [];

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly SequenceGenerator $sequences,
    ) {}

    /**
     * Post a draft.
     *
     * Posted immediately rather than left as a draft: these entries are
     * produced by the system in response to something that has already
     * happened, and an unposted record of a payment that was taken helps
     * nobody. Manual entries made by a bookkeeper are the exception and go
     * through {@see draft()}.
     *
     * @throws UnbalancedEntryException
     */
    public function post(JournalDraft $draft): ?JournalEntry
    {
        $entry = $this->write($draft, JournalEntry::STATUS_POSTED);

        return $entry;
    }

    /**
     * Write an entry a person will review and post later.
     */
    public function draft(JournalDraft $draft): ?JournalEntry
    {
        return $this->write($draft, JournalEntry::STATUS_DRAFT);
    }

    /**
     * Post an entry that was previously drafted.
     */
    public function postDraft(JournalEntry $entry): JournalEntry
    {
        if ($entry->status === JournalEntry::STATUS_POSTED) {
            return $entry;
        }

        if ($entry->status === JournalEntry::STATUS_REVERSED) {
            throw new RuntimeException(sprintf('Entry %s has been reversed and cannot be posted.', $entry->reference));
        }

        $this->assertLinesBalance($entry);

        $entry->forceFill([
            'status' => JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
            'posted_by_id' => auth()->id(),
        ])->save();

        return $entry;
    }

    /**
     * Reverse a posted entry.
     *
     * The reversal is a new entry with every line's sides swapped, dated when
     * the correction was made rather than when the original was. Backdating it
     * would silently change a period somebody has already reported on.
     */
    public function reverse(JournalEntry $entry, ?string $reason = null, ?CarbonImmutable $on = null): JournalEntry
    {
        if ($entry->status !== JournalEntry::STATUS_POSTED) {
            throw new RuntimeException(sprintf(
                'Only a posted entry can be reversed; %s is %s.',
                $entry->reference,
                $entry->status,
            ));
        }

        if ($entry->reversed_by_entry_id !== null) {
            throw new RuntimeException(sprintf('Entry %s has already been reversed.', $entry->reference));
        }

        return DB::transaction(function () use ($entry, $reason, $on): JournalEntry {
            $organization = $this->tenancy->organizationOrFail();

            $reversal = JournalEntry::query()->create([
                'organization_id' => $entry->organization_id,
                'reference' => $this->nextReference($organization->getKey()),
                'entry_date' => ($on ?? CarbonImmutable::today())->toDateString(),
                'description' => $reason ?? sprintf('Reversal of %s', $entry->reference),
                'source' => 'reversal',
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'currency' => $entry->currency,
                'status' => JournalEntry::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by_id' => auth()->id(),
                'reverses_entry_id' => $entry->getKey(),
                'property_id' => $entry->property_id,
                'owner_id' => $entry->owner_id,
                'reservation_id' => $entry->reservation_id,
                'metadata' => ['reversal_of' => $entry->reference],
            ]);

            foreach ($entry->lines as $line) {
                JournalLine::query()->create([
                    'organization_id' => $line->organization_id,
                    'journal_entry_id' => $reversal->getKey(),
                    'ledger_account_id' => $line->ledger_account_id,
                    // Sides swapped: that is the whole of what a reversal is.
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'currency' => $line->currency,
                    'base_debit' => $line->base_credit,
                    'base_credit' => $line->base_debit,
                    'base_currency' => $line->base_currency,
                    'exchange_rate' => $line->exchange_rate,
                    'memo' => sprintf('Reversal: %s', $line->memo ?? ''),
                    'property_id' => $line->property_id,
                    'owner_id' => $line->owner_id,
                    'reservation_id' => $line->reservation_id,
                    'unit_id' => $line->unit_id,
                ]);
            }

            // The original is marked, not edited: its lines are untouched.
            $entry->forceFill([
                'status' => JournalEntry::STATUS_REVERSED,
                'reversed_by_entry_id' => $reversal->getKey(),
            ])->save();

            return $reversal;
        });
    }

    /**
     * The balance of an account, as at a date.
     *
     * Expressed in the direction the account normally carries, so an asset
     * with more debits than credits reads positive and a liability with more
     * credits than debits reads positive too. Reporting raw debit-minus-credit
     * would make half the balance sheet negative and every reader do the
     * arithmetic in their head.
     */
    public function balanceOf(LedgerAccount $account, ?CarbonImmutable $asAt = null): Money
    {
        $totals = JournalLine::query()
            ->where('ledger_account_id', $account->getKey())
            ->whereHas('entry', function ($query) use ($asAt): void {
                // Posted *and* reversed: a reversal cancels its original, so
                // both halves must be counted or the cancellation invents a
                // balance of its own.
                $query->effective();

                if ($asAt !== null) {
                    $query->where('entry_date', '<=', $asAt->toDateString());
                }
            })
            ->selectRaw('COALESCE(SUM(debit), 0) AS debits, COALESCE(SUM(credit), 0) AS credits')
            ->first();

        $net = (int) $totals->debits - (int) $totals->credits;

        return Money::of(
            $account->type->debitIncreases() ? $net : -$net,
            $account->currency,
        );
    }

    /**
     * Whether the whole ledger balances.
     *
     * Cheap, and the single most useful integrity check the product has: if
     * this is ever non-zero, something wrote lines without going through this
     * service.
     */
    public function trialBalance(?CarbonImmutable $asAt = null): Money
    {
        $organization = $this->tenancy->organizationOrFail();

        $totals = JournalLine::query()
            ->whereHas('entry', function ($query) use ($asAt): void {
                // Posted *and* reversed: a reversal cancels its original, so
                // both halves must be counted or the cancellation invents a
                // balance of its own.
                $query->effective();

                if ($asAt !== null) {
                    $query->where('entry_date', '<=', $asAt->toDateString());
                }
            })
            ->selectRaw('COALESCE(SUM(debit), 0) AS debits, COALESCE(SUM(credit), 0) AS credits')
            ->first();

        return Money::of(
            (int) $totals->debits - (int) $totals->credits,
            $organization->base_currency,
        );
    }

    /**
     * Resolve an account by its system key.
     *
     * @throws RuntimeException when the chart of accounts has not been installed.
     */
    public function account(string $systemKey): LedgerAccount
    {
        $organizationId = $this->tenancy->organizationOrFail()->getKey();
        $cacheKey = $organizationId.':'.$systemKey;

        if (isset($this->accounts[$cacheKey])) {
            return $this->accounts[$cacheKey];
        }

        $account = LedgerAccount::query()
            ->where('system_key', $systemKey)
            ->first();

        if ($account === null) {
            throw new RuntimeException(sprintf(
                'No ledger account is mapped to [%s]. Run the chart of accounts installer for this organization.',
                $systemKey,
            ));
        }

        return $this->accounts[$cacheKey] = $account;
    }

    /**
     * Forget resolved accounts. Called between tenants in long-running
     * processes, where a cached account from one organization would otherwise
     * be posted to for another.
     */
    public function flush(): void
    {
        $this->accounts = [];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function write(JournalDraft $draft, string $status): ?JournalEntry
    {
        // An empty draft is not an error: a reprice that changed nothing, or a
        // fee of zero, legitimately produces no lines.
        if ($draft->isEmpty()) {
            return null;
        }

        if (! $draft->balances()) {
            throw new UnbalancedEntryException($draft);
        }

        $organization = $this->tenancy->organizationOrFail();
        $currency = $draft->currency() ?? $organization->base_currency;

        return DB::transaction(function () use ($draft, $status, $organization, $currency): JournalEntry {
            $entry = JournalEntry::query()->create([
                'organization_id' => $organization->getKey(),
                'reference' => $this->nextReference($organization->getKey()),
                'entry_date' => $draft->entryDate->toDateString(),
                'description' => $draft->description,
                'source' => $draft->source,
                'source_type' => $draft->subject?->getMorphClass(),
                'source_id' => $draft->subject?->getKey(),
                'currency' => $currency,
                'status' => $status,
                'posted_at' => $status === JournalEntry::STATUS_POSTED ? now() : null,
                'posted_by_id' => $status === JournalEntry::STATUS_POSTED ? auth()->id() : null,
                'property_id' => $draft->propertyId,
                'owner_id' => $draft->ownerId,
                'reservation_id' => $draft->reservationId,
                'metadata' => $draft->metadata === [] ? null : $draft->metadata,
            ]);

            foreach ($draft->lines() as $line) {
                $this->writeLine($entry, $draft, $line, $organization->base_currency);
            }

            return $entry;
        });
    }

    /**
     * @param  array{account: string, debit: Money|null, credit: Money|null, memo: string|null, attribution: array<string, string|null>}  $line
     */
    private function writeLine(
        JournalEntry $entry,
        JournalDraft $draft,
        array $line,
        string $baseCurrency,
    ): void {
        $account = $this->account($line['account']);

        $debit = $line['debit'];
        $credit = $line['credit'];
        $amount = $debit ?? $credit;

        // The reporting-currency view of the same line. A booking priced in
        // sterling for an organization that reports in euros keeps both, with
        // the rate that was used — the original amount is never overwritten,
        // because a later rate change must not silently restate history.
        $rate = $this->rateFor($amount->currency, $baseCurrency, $draft);
        $baseAmount = $rate === 1.0
            ? $amount
            : Money::of((int) round($amount->minorUnits * $rate), $baseCurrency);

        JournalLine::query()->create([
            'organization_id' => $entry->organization_id,
            'journal_entry_id' => $entry->getKey(),
            'ledger_account_id' => $account->getKey(),
            'debit' => $debit?->minorUnits ?? 0,
            'credit' => $credit?->minorUnits ?? 0,
            'currency' => $amount->currency,
            'base_debit' => $debit === null ? 0 : $baseAmount->minorUnits,
            'base_credit' => $credit === null ? 0 : $baseAmount->minorUnits,
            'base_currency' => $baseCurrency,
            'exchange_rate' => $rate,
            'memo' => $line['memo'],
            // Line-level attribution falls back to the entry's, since a line
            // attributed to a different property from its entry is nearly
            // always a mistake — but a payout spanning several properties
            // genuinely needs to override it.
            'property_id' => $line['attribution']['property_id'] ?? $draft->propertyId,
            'owner_id' => $line['attribution']['owner_id'] ?? $draft->ownerId,
            'reservation_id' => $line['attribution']['reservation_id'] ?? $draft->reservationId,
            'unit_id' => $line['attribution']['unit_id'] ?? null,
        ]);
    }

    /**
     * The rate to restate a line into the reporting currency.
     *
     * Taken from the draft's metadata when the caller knows it — a reservation
     * carries the rate it was booked at — and otherwise 1, which is correct
     * whenever the currencies match and honest when they do not: an entry
     * posted at a rate nobody supplied should be visibly unconverted rather
     * than converted at a rate invented here.
     */
    private function rateFor(string $from, string $to, JournalDraft $draft): float
    {
        if ($from === $to) {
            return 1.0;
        }

        return (float) ($draft->metadata['exchange_rate'] ?? 1.0);
    }

    private function nextReference(string $organizationId): string
    {
        return $this->sequences->next($organizationId, SequenceGenerator::JOURNAL, 'JE', 6);
    }

    private function assertLinesBalance(JournalEntry $entry): void
    {
        $lines = $entry->lines()->get();

        $net = $lines->sum(fn (JournalLine $line): int => (int) $line->debit - (int) $line->credit);

        if ($net !== 0) {
            throw new RuntimeException(sprintf(
                'Entry %s does not balance: debits exceed credits by %d minor units.',
                $entry->reference,
                $net,
            ));
        }
    }
}
