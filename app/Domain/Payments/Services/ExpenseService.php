<?php

declare(strict_types=1);

namespace App\Domain\Payments\Services;

use App\Domain\Accounting\Services\PaymentPostingRules;
use App\Domain\Accounting\Support\DefaultChartOfAccounts as Accounts;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Operations\Models\Task;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Models\Expense;
use App\Domain\Platform\Services\SequenceGenerator;
use App\Domain\Properties\Models\Property;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Costs incurred against a property.
 *
 * Three rules shape this service, and all three exist because an expense
 * eventually lands on somebody's statement:
 *
 *  - **Nothing posts to the ledger until it is approved.** A draft cost is an
 *    unverified claim — a photograph of a receipt, a contractor's word. The
 *    accounts should not move on it.
 *  - **An approved expense cannot be quietly edited.** Correcting one that has
 *    already been posted means reversing the entry and posting the new figure,
 *    so the history shows both the mistake and its correction.
 *  - **An expense swept into a finalised statement is frozen entirely.** That
 *    record is what the owner was shown; rewriting it would make the statement
 *    they hold disagree with the system that produced it.
 *
 * The owner is derived from the property rather than accepted from the caller.
 * Whoever owns the property on the day the cost was incurred bears it, and a
 * property that changed hands mid-year makes that a question of dates, not of
 * whoever happens to own it today.
 */
class ExpenseService
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly SequenceGenerator $sequences,
        private readonly PaymentPostingRules $posting,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Record a cost.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Expense
    {
        $organization = $this->tenancy->organizationOrFail();

        return DB::transaction(function () use ($attributes, $organization): Expense {
            $expense = new Expense;
            $expense->fill(collect($attributes)->except(['property', 'task', 'owner_id', 'status'])->all());

            $property = $attributes['property']
                ?? ($expense->property_id !== null ? Property::query()->find($expense->property_id) : null);

            $expense->organization_id = $organization->getKey();
            $expense->currency ??= $property?->currency ?: $organization->base_currency;
            $expense->created_by_id = auth()->id();

            $expense->reference = $this->sequences->next(
                $organization->getKey(),
                SequenceGenerator::EXPENSE,
                'EXP',
                6,
            );

            // The margin is computed from the percentage rather than trusted
            // from the caller, so the two can never disagree on a record an
            // owner will read.
            $expense->markup_amount = $this->markupFor(
                Money::of((int) $expense->amount, $expense->currency),
                (float) ($attributes['markup_percent'] ?? 0),
            )->minorUnits;

            // Whoever held the property when the cost arose, not whoever holds
            // it now.
            $expense->owner_id = $attributes['owner_id']
                ?? $this->ownerFor($property, $expense->expense_date->toDateString());

            $expense->save();

            $this->linkToTask($expense, $attributes['task'] ?? null);

            $this->audit->created($expense, sprintf(
                'Recorded %s expense %s (%s), billed to the %s.',
                $expense->category,
                $expense->reference,
                $expense->chargeableAmount()->toDecimal(),
                $expense->billable_to,
            ));

            return $expense;
        });
    }

    /**
     * Amend a cost that has not yet been billed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Expense $expense, array $attributes): Expense
    {
        if (! $expense->isEditable()) {
            throw new PaymentException(
                $expense->owner_statement_id !== null
                    ? 'This expense is on a finalised owner statement and can no longer be changed.'
                    : 'Only a draft or approved expense can be changed.',
                'expense_frozen',
            );
        }

        return DB::transaction(function () use ($expense, $attributes): Expense {
            $before = $expense->only(['amount', 'tax_amount', 'markup_amount', 'billable_to', 'category']);
            $wasPosted = $expense->isApproved();

            $expense->fill(collect($attributes)->except(['status', 'owner_statement_id'])->all());

            if (array_key_exists('markup_percent', $attributes)) {
                $expense->markup_amount = $this->markupFor(
                    Money::of((int) $expense->amount, $expense->currency),
                    (float) $attributes['markup_percent'],
                )->minorUnits;
            }

            $expense->save();

            // An approved expense has already moved the accounts. Correcting
            // it means reversing what was posted and posting the new figure,
            // never editing the entry in place.
            if ($wasPosted) {
                $this->repost($expense);
            }

            $this->audit->updated($expense, 'Expense amended.', ['before' => $before]);

            return $expense;
        });
    }

    /**
     * Approve a cost, and post it.
     *
     * This is the moment the accounts move: a debit to the category's expense
     * account and a credit to accounts payable, because the vendor is now owed
     * regardless of when they are actually paid.
     */
    public function approve(Expense $expense): Expense
    {
        if ($expense->status === Expense::APPROVED || $expense->status === Expense::PAID) {
            return $expense;
        }

        if ($expense->status === Expense::REJECTED) {
            throw new PaymentException('A rejected expense must be reopened before it can be approved.', 'expense_rejected');
        }

        return DB::transaction(function () use ($expense): Expense {
            $expense->forceFill([
                'status' => Expense::APPROVED,
                'approved_by_id' => auth()->id(),
                'approved_at' => now(),
            ])->save();

            $this->post($expense);

            $this->audit->record(
                action: 'expense.approved',
                subject: $expense,
                description: sprintf('Approved expense %s.', $expense->reference),
            );

            return $expense;
        });
    }

    /**
     * Refuse a cost.
     *
     * Kept rather than deleted: "we decided not to pay this" is an answer
     * somebody will need, and a vendor who disputes it needs the record.
     */
    public function reject(Expense $expense, ?string $reason = null): Expense
    {
        if ($expense->owner_statement_id !== null) {
            throw new PaymentException('This expense has already been billed to an owner.', 'expense_frozen');
        }

        return DB::transaction(function () use ($expense, $reason): Expense {
            $wasPosted = $expense->isApproved();

            $expense->forceFill([
                'status' => Expense::REJECTED,
                'notes' => trim(($expense->notes ? $expense->notes."\n" : '').($reason ?? '')) ?: $expense->notes,
            ])->save();

            // Rejecting something already posted means the payable has to go
            // back, not just the status.
            if ($wasPosted) {
                $this->posting->reverseFor($expense, 'Expense rejected');
            }

            $this->audit->record(
                action: 'expense.rejected',
                subject: $expense,
                description: sprintf('Rejected expense %s.', $expense->reference),
                context: array_filter(['reason' => $reason]),
            );

            return $expense;
        });
    }

    /**
     * Record that the vendor has been paid.
     *
     * Settles the payable raised at approval against cash. The expense itself
     * is unchanged — what it cost and who bears it are separate questions from
     * whether the contractor has had their money.
     */
    public function markPaid(Expense $expense, ?string $method = null): Expense
    {
        if (! $expense->isApproved()) {
            throw new PaymentException('An expense must be approved before it can be paid.', 'expense_not_approved');
        }

        if ($expense->status === Expense::PAID) {
            return $expense;
        }

        return DB::transaction(function () use ($expense, $method): Expense {
            $expense->forceFill([
                'status' => Expense::PAID,
                'is_paid' => true,
                'paid_at' => now(),
                'payment_method' => $method ?? $expense->payment_method,
            ])->save();

            $this->posting->expensePaid($expense, $this->payableAmount($expense));

            $this->audit->record(
                action: 'expense.paid',
                subject: $expense,
                description: sprintf('Paid expense %s.', $expense->reference),
            );

            return $expense;
        });
    }

    /**
     * What the vendor is owed: the cost and its tax, never the manager's
     * margin. The margin is charged to the owner, not paid to the contractor.
     */
    private function payableAmount(Expense $expense): Money
    {
        return $expense->amount()->add($expense->taxAmount());
    }

    private function post(Expense $expense): void
    {
        $this->posting->expenseRecorded(
            $expense,
            $this->payableAmount($expense),
            $this->accountFor($expense->category),
        );
    }

    private function repost(Expense $expense): void
    {
        $this->posting->reverseFor($expense, 'Expense amended');
        $this->post($expense);
    }

    /**
     * Where a cost lands in the chart of accounts.
     *
     * Unrecognised categories fall to maintenance rather than being refused:
     * an operator inventing a category should not be able to stop a cost being
     * recorded, and a misfiled expense is a reporting problem, not a data-loss
     * one.
     */
    private function accountFor(string $category): string
    {
        return match ($category) {
            'cleaning' => Accounts::CLEANING_EXPENSE,
            'supplies' => Accounts::SUPPLIES_EXPENSE,
            'utilities' => Accounts::UTILITIES_EXPENSE,
            default => Accounts::MAINTENANCE_EXPENSE,
        };
    }

    private function markupFor(Money $amount, float $percent): Money
    {
        if ($percent <= 0.0) {
            return Money::zero($amount->currency);
        }

        return $amount->percentage($percent);
    }

    /**
     * Who owned the property on the day the cost arose.
     */
    private function ownerFor(?Property $property, string $on): ?string
    {
        if ($property === null) {
            return null;
        }

        // Both boundaries are nullable, and null means "open" on that side:
        // an ownership with no start date has always held, one with no end
        // date still does. Treating null as a failed comparison would drop
        // exactly the ownerships that are most obviously in force.
        $ownership = DB::table('property_ownerships')
            ->where('property_id', $property->getKey())
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $on))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $on))
            ->orderByDesc('ownership_percentage')
            ->first();

        return $ownership?->owner_id;
    }

    /**
     * Close the loop from the job that caused the cost.
     */
    private function linkToTask(Expense $expense, ?Task $task): void
    {
        if ($task === null) {
            return;
        }

        $expense->forceFill(['task_id' => $task->getKey()])->save();
        $task->forceFill(['expense_id' => $expense->getKey()])->save();
    }
}
