<?php

declare(strict_types=1);

namespace App\Domain\OwnerAccounting\Services;

use App\Domain\Accounting\DataObjects\JournalDraft;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\DefaultChartOfAccounts as Accounts;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\OwnerAccounting\Exceptions\OwnerStatementException;
use App\Domain\OwnerAccounting\Models\OwnerPayout;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Platform\Services\SequenceGenerator;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Sending owners their money.
 *
 * A payout is a separate record from the statement that justified it, for the
 * same reason a payment is separate from an invoice: the statement says what
 * is owed, the payout says what was sent. They can differ — a transfer fails,
 * an owner asks to be paid in two parts, a bank rejects an account — and
 * collapsing them would make "you never paid me" unanswerable.
 *
 * Two properties matter more than the rest:
 *
 *  - **The destination is snapshotted.** Where the money went is recorded at
 *    the moment it went, so an owner changing bank next year cannot make last
 *    year's payout appear to have gone somewhere else.
 *  - **The ledger moves when the money does, not when the payout is planned.**
 *    A pending payout is an intention; only a completed one debits the owner
 *    payable and credits cash.
 */
class OwnerPayoutService
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly SequenceGenerator $sequences,
        private readonly JournalPoster $poster,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Raise a payout for an approved statement.
     *
     * Refuses a draft statement: paying against figures that can still change
     * is how an owner ends up paid twice for a period that was rebuilt.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function fromStatement(OwnerStatement $statement, array $attributes = []): OwnerPayout
    {
        if ($statement->status === OwnerStatement::STATUS_DRAFT) {
            throw new OwnerStatementException('A statement must be approved before it can be paid.');
        }

        $existing = OwnerPayout::query()
            ->where('owner_statement_id', $statement->getKey())
            ->whereNotIn('status', ['cancelled', 'failed'])
            ->first();

        // Idempotent by statement: a second click, a retried request or a
        // duplicated job must not send the money twice.
        if ($existing !== null) {
            return $existing;
        }

        $amount = Money::of((int) $statement->payout_amount, $statement->currency);

        if (! $amount->isPositive()) {
            throw new OwnerStatementException(sprintf(
                'Statement %s has nothing to pay out; the balance is carried forward instead.',
                $statement->reference,
            ));
        }

        // The link lives on the payout, not on the statement: one statement
        // can legitimately produce a second payout after a failed transfer,
        // and a single column on the statement could not hold both.
        return $this->create(
            $statement->owner,
            $amount,
            $attributes + ['owner_statement_id' => $statement->getKey()],
        );
    }

    /**
     * Raise a payout directly — an advance, a correction, a one-off.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(Owner $owner, Money $amount, array $attributes = []): OwnerPayout
    {
        $organization = $this->tenancy->organizationOrFail();

        if (! $amount->isPositive()) {
            throw new OwnerStatementException('A payout must be for a positive amount.');
        }

        return DB::transaction(function () use ($owner, $amount, $attributes, $organization): OwnerPayout {
            $payout = new OwnerPayout;

            $payout->fill(collect($attributes)->except(['amount', 'currency', 'status'])->all());

            $payout->organization_id = $organization->getKey();
            $payout->owner_id = $owner->getKey();
            $payout->amount = $amount->minorUnits;
            $payout->currency = $amount->currency;
            $payout->method ??= $owner->payout_method ?: 'bank_transfer';
            $payout->created_by_id = auth()->id();

            $payout->reference = $this->sequences->next(
                $organization->getKey(),
                SequenceGenerator::PAYOUT,
                'PO',
                6,
            );

            // Where the money is going, as it stands today. Frozen onto the
            // record so a later change of bank cannot rewrite history.
            $payout->destination_snapshot = $this->destinationFor($owner);

            $payout->save();

            $this->audit->created($payout, sprintf(
                'Raised payout %s to %s for %s.',
                $payout->reference,
                $owner->display_name ?? $owner->getKey(),
                $amount->toDecimal(),
            ));

            return $payout;
        });
    }

    /**
     * Mark a payout as sent, and move the ledger.
     *
     * This is the only place cash leaves for an owner. Debits the owner
     * payable — what we owed them — and credits cash.
     */
    public function markPaid(OwnerPayout $payout, ?string $externalReference = null): OwnerPayout
    {
        if ($payout->isPaid()) {
            return $payout;
        }

        if ($payout->status === 'cancelled') {
            throw new OwnerStatementException('A cancelled payout cannot be paid.');
        }

        return DB::transaction(function () use ($payout, $externalReference): OwnerPayout {
            $payout->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
                'external_reference' => $externalReference ?? $payout->external_reference,
                'failure_message' => null,
            ])->save();

            $this->poster->post(
                JournalDraft::for(
                    description: sprintf('Owner payout %s', $payout->reference),
                    source: 'owner.payout',
                    subject: $payout,
                    ownerId: $payout->owner_id,
                )
                    ->debit(Accounts::OWNER_PAYABLE, $payout->amount(), 'Owner paid')
                    ->credit(Accounts::CASH, $payout->amount(), 'Transferred to the owner'),
            );

            if ($payout->owner_statement_id !== null) {
                OwnerStatement::query()
                    ->whereKey($payout->owner_statement_id)
                    ->update(['status' => OwnerStatement::STATUS_PAID, 'paid_at' => now()]);
            }

            $this->audit->record(
                action: 'owner_payout.paid',
                subject: $payout,
                description: sprintf('Payout %s sent.', $payout->reference),
            );

            return $payout->refresh();
        });
    }

    /**
     * The transfer did not go through.
     *
     * Nothing is posted and nothing is deleted: the owner is still owed, the
     * statement stays approved, and the failed attempt stays on the record so
     * a second attempt is visibly a second attempt.
     */
    public function markFailed(OwnerPayout $payout, string $reason): OwnerPayout
    {
        if ($payout->isPaid()) {
            throw new OwnerStatementException('A payout that has already been sent cannot be marked as failed.');
        }

        $payout->forceFill(['status' => 'failed', 'failure_message' => $reason])->save();

        $this->audit->record(
            action: 'owner_payout.failed',
            subject: $payout,
            description: sprintf('Payout %s failed: %s', $payout->reference, $reason),
        );

        return $payout;
    }

    /**
     * Withdraw a payout that has not been sent.
     */
    public function cancel(OwnerPayout $payout, ?string $reason = null): OwnerPayout
    {
        if ($payout->isPaid()) {
            throw new OwnerStatementException('A payout that has already been sent cannot be cancelled.');
        }

        $payout->forceFill([
            'status' => 'cancelled',
            'notes' => trim(($payout->notes ? $payout->notes."\n" : '').($reason ?? '')) ?: $payout->notes,
        ])->save();

        // The statement itself is untouched: it is still approved and still
        // owed. Cancelling frees it to be paid again, because `fromStatement`
        // ignores cancelled payouts when it checks for a duplicate.
        $this->audit->record(
            action: 'owner_payout.cancelled',
            subject: $payout,
            description: sprintf('Payout %s cancelled.', $payout->reference),
            context: array_filter(['reason' => $reason]),
        );

        return $payout;
    }

    /**
     * The banking details as they stand, masked.
     *
     * Deliberately not the full account number. This snapshot is read back on
     * screens and in exports; enough to identify where money went is enough,
     * and the full details live encrypted on the owner record.
     *
     * @return array<string, mixed>
     */
    private function destinationFor(Owner $owner): array
    {
        $account = (string) ($owner->bank_account_number ?? '');

        return array_filter([
            'method' => $owner->payout_method,
            'bank_name' => $owner->bank_name,
            'account_holder' => $owner->bank_account_name,
            'account_last4' => $account !== '' ? substr($account, -4) : null,
            'iban_last4' => $owner->bank_iban !== null ? substr((string) $owner->bank_iban, -4) : null,
            'currency' => $owner->payout_currency,
            'captured_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== '');
    }
}
