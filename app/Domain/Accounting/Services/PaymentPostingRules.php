<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\DataObjects\JournalDraft;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Support\DefaultChartOfAccounts as Accounts;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * How money movements become ledger entries.
 *
 * Kept apart from the payment service on purpose. The payment service knows
 * about processors, retries and card state; this knows about debits and
 * credits. Mixing them is how an accounting rule ends up changed by somebody
 * fixing a webhook, and how a webhook ends up broken by an accountant.
 *
 * Three distinctions drive almost everything here.
 *
 * **Authorised is not captured.** An authorisation moves no money and posts
 * nothing. Recognising it would overstate cash by every unarrived booking.
 *
 * **A security deposit is not revenue.** It is the guest's money held against
 * damage, so it credits a liability. Treating it as income overstates revenue
 * and understates what is owed back, compounding every booking.
 *
 * **Collected-by-us is not the same as paid.** When a channel takes the
 * guest's money itself, the guest has paid but we hold nothing: the debit goes
 * to a receivable from the channel, not to cash.
 */
class PaymentPostingRules
{
    public function __construct(private readonly JournalPoster $poster) {}

    /**
     * A capture: money has actually been taken.
     */
    public function captured(Payment $payment, Money $amount, ?Money $fee = null): ?JournalEntry
    {
        $fee ??= Money::zero($amount->currency);

        // Where the money landed. A channel-collected payment never reached
        // our bank, so it becomes a receivable from the channel rather than
        // cash we do not have.
        $destination = $payment->is_collected_by_us
            ? Accounts::CLEARING
            : Accounts::ACCOUNTS_RECEIVABLE;

        $draft = JournalDraft::for(
            description: sprintf('Payment %s captured', $payment->reference),
            source: 'payment.captured',
            entryDate: CarbonImmutable::today(),
            subject: $payment,
            propertyId: $payment->property_id,
            reservationId: $payment->reservation_id,
            metadata: ['exchange_rate' => (float) $payment->exchange_rate],
        );

        // Gross in, processor's cut straight out: recording only the net would
        // lose the cost of taking money, which is a real expense somebody has
        // to account for.
        $draft = $draft->debit($destination, $amount->subtract($fee), 'Net of processing fees');

        if ($fee->isPositive()) {
            $draft = $draft->debit(Accounts::PAYMENT_PROCESSING, $fee, 'Processing fee');
        }

        $draft = $draft->credit($this->creditAccountFor($payment), $amount, $this->memoFor($payment));

        return $this->poster->post($draft);
    }

    /**
     * A refund that has completed.
     */
    public function refunded(Refund $refund): ?JournalEntry
    {
        $payment = $refund->payment;

        if ($payment === null) {
            return null;
        }

        $amount = $refund->amount();

        $source = $payment->is_collected_by_us
            ? Accounts::CLEARING
            : Accounts::ACCOUNTS_RECEIVABLE;

        // A security deposit returned simply discharges the liability; it was
        // never revenue, so there is nothing to reverse out of income.
        $debitAccount = $payment->kind->isRefundableHolding()
            ? Accounts::SECURITY_DEPOSITS_HELD
            : Accounts::REFUNDS;

        return $this->poster->post(
            JournalDraft::for(
                description: sprintf('Refund %s', $refund->reference),
                source: 'payment.refunded',
                subject: $refund,
                propertyId: $payment->property_id,
                reservationId: $refund->reservation_id ?? $payment->reservation_id,
            )
                ->debit($debitAccount, $amount, $refund->reason)
                ->credit($source, $amount),
        );
    }

    /**
     * A booking confirmed: the guest owes us, and we owe them a stay.
     *
     * Revenue is *not* recognised here. The money is deferred until the nights
     * are actually stayed, which is both the correct treatment and the only
     * one that makes a mid-stay cancellation arithmetically sane.
     */
    public function reservationConfirmed(
        object $reservation,
        Money $accommodation,
        Money $fees,
        Money $taxes,
    ): ?JournalEntry {
        $total = $accommodation->add($fees)->add($taxes);

        if ($total->isZero()) {
            return null;
        }

        return $this->poster->post(
            JournalDraft::for(
                description: sprintf('Booking %s confirmed', $reservation->confirmation_code),
                source: 'reservation.confirmed',
                subject: $reservation,
                propertyId: $reservation->property_id,
                reservationId: $reservation->getKey(),
                metadata: ['exchange_rate' => (float) ($reservation->exchange_rate ?? 1)],
            )
                ->debit(Accounts::ACCOUNTS_RECEIVABLE, $total, 'Owed by the guest')
                ->credit(Accounts::DEFERRED_REVENUE, $accommodation, 'Accommodation, not yet stayed')
                ->credit(Accounts::CLEANING_REVENUE, $fees, 'Fees')
                // Tax is collected on somebody else's behalf and is never ours,
                // so it is a liability from the moment it is charged.
                ->credit(Accounts::TAX_PAYABLE, $taxes, 'Lodging tax collected'),
        );
    }

    /**
     * Nights actually stayed: deferred revenue becomes earned revenue.
     *
     * This is what makes a revenue report mean something. A booking made in
     * January for August belongs to August, and recognising it in January
     * would make every forward-booking month look like a record and every
     * stayed month look empty.
     */
    public function revenueEarned(
        object $reservation,
        Money $accommodation,
        CarbonImmutable $on,
    ): ?JournalEntry {
        if ($accommodation->isZero()) {
            return null;
        }

        return $this->poster->post(
            JournalDraft::for(
                description: sprintf('Revenue earned on %s', $reservation->confirmation_code),
                source: 'reservation.revenue_earned',
                entryDate: $on,
                subject: $reservation,
                propertyId: $reservation->property_id,
                reservationId: $reservation->getKey(),
            )
                ->debit(Accounts::DEFERRED_REVENUE, $accommodation)
                ->credit(Accounts::ACCOMMODATION_REVENUE, $accommodation, 'Nights stayed'),
        );
    }

    /**
     * A booking cancelled before any of it was stayed.
     *
     * Releases what was never earned. Cancellation *fees* the guest forfeits
     * are recognised separately, because they are revenue and the accommodation
     * is not.
     */
    public function reservationCancelled(
        object $reservation,
        Money $unearnedAccommodation,
        Money $fees,
        Money $taxes,
        Money $retainedFee,
    ): ?JournalEntry {
        $released = $unearnedAccommodation->add($fees)->add($taxes);

        if ($released->isZero() && $retainedFee->isZero()) {
            return null;
        }

        $draft = JournalDraft::for(
            description: sprintf('Booking %s cancelled', $reservation->confirmation_code),
            source: 'reservation.cancelled',
            subject: $reservation,
            propertyId: $reservation->property_id,
            reservationId: $reservation->getKey(),
        );

        $draft = $draft
            ->debit(Accounts::DEFERRED_REVENUE, $unearnedAccommodation, 'Released on cancellation')
            ->debit(Accounts::CLEANING_REVENUE, $fees, 'Released on cancellation')
            ->debit(Accounts::TAX_PAYABLE, $taxes, 'Tax no longer due')
            ->credit(Accounts::ACCOUNTS_RECEIVABLE, $released, 'No longer owed');

        // What the guest forfeits under the cancellation policy is earned the
        // moment they cancel: no stay is coming, so there is nothing to defer.
        if ($retainedFee->isPositive()) {
            $draft = $draft
                ->debit(Accounts::ACCOUNTS_RECEIVABLE, $retainedFee, 'Cancellation fee retained')
                ->credit(Accounts::FEE_REVENUE, $retainedFee, 'Cancellation fee');
        }

        return $this->poster->post($draft);
    }

    /**
     * A cost incurred against a property.
     */
    public function expenseRecorded(
        object $expense,
        Money $amount,
        string $accountKey,
    ): ?JournalEntry {
        if ($amount->isZero()) {
            return null;
        }

        return $this->poster->post(
            JournalDraft::for(
                description: sprintf('Expense %s: %s', $expense->reference, $expense->description),
                source: 'expense.recorded',
                entryDate: CarbonImmutable::parse($expense->expense_date),
                subject: $expense,
                propertyId: $expense->property_id,
                ownerId: $expense->owner_id,
            )
                ->debit($accountKey, $amount, $expense->description)
                ->credit(Accounts::ACCOUNTS_PAYABLE, $amount, 'Owed to the vendor'),
        );
    }

    /**
     * The vendor has actually been paid.
     *
     * Settles the payable raised when the cost was approved. The expense
     * account is untouched: what it cost was decided then, and paying it does
     * not change the figure, only who is holding the money.
     */
    public function expensePaid(object $expense, Money $amount): ?JournalEntry
    {
        if ($amount->isZero()) {
            return null;
        }

        return $this->poster->post(
            JournalDraft::for(
                description: sprintf('Expense %s paid', $expense->reference),
                source: 'expense.paid',
                subject: $expense,
                propertyId: $expense->property_id,
                ownerId: $expense->owner_id,
            )
                ->debit(Accounts::ACCOUNTS_PAYABLE, $amount, 'Vendor settled')
                ->credit(Accounts::CASH, $amount, 'Paid out'),
        );
    }

    /**
     * Undo whatever a record posted, by reversing it.
     *
     * Never by deleting the entry. A posted entry is immutable, so "this was
     * wrong" is itself a transaction: the reversal stands beside the original
     * and the pair nets to nothing, leaving the mistake and its correction
     * both visible.
     *
     * Reverses every live entry the subject produced, because a record may
     * have posted more than once — an expense that was approved, paid, and
     * then found to be somebody else's bill.
     *
     * @return list<JournalEntry> the reversals written
     */
    public function reverseFor(object $subject, ?string $reason = null): array
    {
        if (! $subject instanceof Model) {
            return [];
        }

        $entries = JournalEntry::query()
            ->where('source_type', $subject->getMorphClass())
            ->where('source_id', $subject->getKey())
            ->where('status', JournalEntry::STATUS_POSTED)
            ->whereNull('reversed_by_entry_id')
            ->get();

        $reversals = [];

        foreach ($entries as $entry) {
            $reversals[] = $this->poster->reverse($entry, $reason);
        }

        return $reversals;
    }

    /**
     * Where a captured payment's credit belongs.
     *
     * A booking payment discharges the receivable raised when the booking was
     * confirmed. A security deposit creates a liability instead, because it is
     * the guest's money and we are merely holding it.
     */
    private function creditAccountFor(Payment $payment): string
    {
        return match (true) {
            $payment->kind->isRefundableHolding() => Accounts::SECURITY_DEPOSITS_HELD,
            $payment->reservation_id !== null => Accounts::ACCOUNTS_RECEIVABLE,
            default => Accounts::FEE_REVENUE,
        };
    }

    private function memoFor(Payment $payment): string
    {
        return $payment->kind->isRefundableHolding()
            ? 'Held against damage; never revenue'
            : sprintf('%s payment', $payment->kind->label());
    }
}
