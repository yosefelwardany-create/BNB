<?php

declare(strict_types=1);

namespace App\Domain\OwnerAccounting\Services;

use App\Domain\Accounting\Models\JournalLine;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\OwnerAccounting\Models\OwnerStatementLine;
use App\Domain\Owners\Models\ManagementAgreement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Models\PropertyOwnership;
use App\Domain\Platform\Services\SequenceGenerator;
use App\Domain\Properties\Models\Property;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds an owner statement for a period.
 *
 * The hardest thing in the product to get right, because four independent
 * facts have to line up and each can change under you:
 *
 *  1. **What the property earned**, taken from the per-night rows rather than
 *     from a booking total. They are the only place each night's revenue
 *     carries its own date, and the date is what decides both which period the
 *     money falls in and which ownership share applies to it.
 *  2. **Whose it was**, from the ownership shares *in force on each night* —
 *     not the shares as they stand today. A property sold in March does not
 *     retroactively give the buyer February's revenue.
 *  3. **What the terms were**, from the management agreement in force for the
 *     period, snapshotted onto the statement. An agreement renegotiated in
 *     June must not restate March.
 *  4. **What it cost**, from expenses the agreement says the owner bears.
 *
 * Everything is attributed night by night rather than booking by booking. A
 * stay spanning a change of ownership, a rate change or a period boundary is
 * ordinary, and attributing the whole booking to whoever owned the property on
 * the check-in date would be wrong in all three cases.
 *
 * A rebuild replaces a draft entirely. Once approved the figures are frozen —
 * enforced by the model — and a correction is a new statement, never an edit.
 */
class OwnerStatementBuilder
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly SequenceGenerator $sequences,
    ) {}

    /**
     * Produce (or rebuild) the draft statement for one owner and period.
     *
     * @param  Property|null  $property  Null covers every property they hold.
     */
    public function build(
        Owner $owner,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?Property $property = null,
    ): OwnerStatement {
        $organization = $this->tenancy->organizationOrFail();
        $currency = $owner->payout_currency ?: $organization->base_currency;

        return DB::transaction(function () use ($owner, $from, $to, $property, $organization, $currency): OwnerStatement {
            $statement = $this->openDraft($owner, $from, $to, $property, $organization->getKey(), $currency);

            $properties = $this->propertiesFor($owner, $property, $from, $to);

            $lines = collect();
            $totals = $this->emptyTotals($currency);
            $nights = 0;
            $reservations = collect();

            foreach ($properties as $propertyId) {
                $contribution = $this->forProperty(
                    $statement,
                    $owner,
                    $propertyId,
                    $from,
                    $to,
                    $currency,
                );

                $lines = $lines->concat($contribution['lines']);
                $totals = $this->addTotals($totals, $contribution['totals']);
                $nights += $contribution['nights'];
                $reservations = $reservations->concat($contribution['reservation_ids']);
            }

            $managementFee = $totals['management_fee'];
            $lastAgreement = null;

            foreach ($properties as $propertyId) {
                $lastAgreement = $owner->agreementFor($propertyId, $to) ?? $lastAgreement;
            }

            $opening = $this->openingBalance($owner, $property, $from, $currency);

            if (! $opening->isZero()) {
                $lines->push($this->line($statement, [
                    'category' => OwnerStatementLine::OPENING_BALANCE,
                    'line_date' => $from,
                    'description' => $opening->isNegative()
                        ? 'Balance carried forward (owed to the manager)'
                        : 'Balance carried forward',
                    'amount' => $opening,
                    'currency' => $currency,
                    'sort_order' => 0,
                ]));
            }

            $netDue = $totals['gross']
                ->subtract($totals['taxes'])
                ->subtract($totals['channel_commission'])
                ->subtract($totals['payment_fees'])
                ->subtract($managementFee)
                ->subtract($totals['expenses'])
                ->add($totals['adjustments']);

            $closing = $netDue->add($opening);

            // A reserve is only withheld from a positive balance: holding back
            // a float from an owner who already owes money would deepen the
            // deficit rather than protect against it.
            $reserve = $this->reserveFor($owner, $closing, $currency);

            if ($reserve->isPositive()) {
                $lines->push($this->line($statement, [
                    'category' => OwnerStatementLine::RESERVE,
                    'line_date' => $to,
                    'description' => 'Reserve withheld for future costs',
                    'amount' => $reserve->negate(),
                    'currency' => $currency,
                    'sort_order' => 80,
                ]));
            }

            $statement->forceFill([
                'gross_revenue' => $totals['gross']->minorUnits,
                'accommodation_revenue' => $totals['accommodation']->minorUnits,
                'fee_revenue' => $totals['fees']->minorUnits,
                'taxes_collected' => $totals['taxes']->minorUnits,
                'channel_commission' => $totals['channel_commission']->minorUnits,
                'payment_fees' => $totals['payment_fees']->minorUnits,
                'management_fee' => $managementFee->minorUnits,
                'expenses_total' => $totals['expenses']->minorUnits,
                'adjustments_total' => $totals['adjustments']->minorUnits,
                'net_due' => $netDue->minorUnits,
                'opening_balance' => $opening->minorUnits,
                'closing_balance' => $closing->minorUnits,
                'reserve_withheld' => $reserve->minorUnits,
                // Never negative: a deficit is carried forward on the closing
                // balance, not demanded back as a payout.
                'payout_amount' => max(0, $closing->subtract($reserve)->minorUnits),
                'nights_sold' => $nights,
                'reservations_count' => $reservations->unique()->count(),
                'agreement_snapshot' => $this->snapshot($lastAgreement),
            ])->save();

            $this->persistLines($statement, $lines);

            return $statement->fresh(['lines']);
        });
    }

    /**
     * Approve a statement, freezing it and marking what it consumed.
     *
     * The marking is what stops a line being swept into a second statement.
     * Done at approval rather than at build time so a draft can be rebuilt as
     * often as anyone likes without consuming anything.
     */
    public function approve(OwnerStatement $statement): OwnerStatement
    {
        if ($statement->status !== OwnerStatement::STATUS_DRAFT) {
            return $statement;
        }

        return DB::transaction(function () use ($statement): OwnerStatement {
            $reservationIds = $statement->lines()
                ->whereNotNull('reservation_id')
                ->pluck('reservation_id')
                ->unique()
                ->all();

            if ($reservationIds !== []) {
                JournalLine::query()
                    ->whereIn('reservation_id', $reservationIds)
                    ->whereNull('owner_statement_id')
                    ->update(['owner_statement_id' => $statement->getKey()]);
            }

            $expenseIds = $statement->lines()
                ->whereNotNull('expense_id')
                ->pluck('expense_id')
                ->all();

            if ($expenseIds !== []) {
                DB::table('expenses')
                    ->whereIn('id', $expenseIds)
                    ->whereNull('owner_statement_id')
                    ->update(['owner_statement_id' => $statement->getKey()]);
            }

            $statement->forceFill([
                'status' => OwnerStatement::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by_id' => auth()->id(),
            ])->save();

            return $statement->fresh();
        });
    }

    /**
     * Withdraw a statement that should never have been issued.
     *
     * Voided rather than deleted, because the owner may be holding a copy and
     * the system must be able to say what it said. What the statement consumed
     * is released — the revenue and expenses behind it become available again
     * — so a corrected statement can pick them up. Without that release the
     * mistake would be permanent: the underlying figures would belong to a
     * statement nobody can see and no replacement could ever include them.
     */
    public function void(OwnerStatement $statement, ?string $reason = null): OwnerStatement
    {
        if ($statement->status === OwnerStatement::STATUS_VOID) {
            return $statement;
        }

        if ($statement->status === OwnerStatement::STATUS_PAID) {
            throw new \RuntimeException(sprintf(
                'Statement %s has been paid and cannot be voided; issue a correcting statement instead.',
                $statement->reference,
            ));
        }

        return DB::transaction(function () use ($statement, $reason): OwnerStatement {
            JournalLine::query()
                ->where('owner_statement_id', $statement->getKey())
                ->update(['owner_statement_id' => null]);

            DB::table('expenses')
                ->where('owner_statement_id', $statement->getKey())
                ->update(['owner_statement_id' => null]);

            $statement->forceFill([
                'status' => OwnerStatement::STATUS_VOID,
                'notes' => trim(($statement->notes ? $statement->notes."\n" : '').($reason ?? '')) ?: $statement->notes,
            ])->save();

            return $statement->fresh();
        });
    }

    // ------------------------------------------------------------------
    // One property's contribution
    // ------------------------------------------------------------------

    /**
     * @return array{lines: Collection<int, array<string, mixed>>, totals: array<string, Money>, nights: int, reservation_ids: Collection<int, string>}
     */
    private function forProperty(
        OwnerStatement $statement,
        Owner $owner,
        string $propertyId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $currency,
    ): array {
        $lines = collect();
        $totals = $this->emptyTotals($currency);
        $nights = 0;
        $reservationIds = collect();

        $shares = PropertyOwnership::query()
            ->where('property_id', $propertyId)
            ->where('owner_id', $owner->getKey())
            ->get();

        // Nights stayed, from the reservation nights themselves: this is the
        // only place the *date* of each night's revenue is known, and the date
        // is what decides both the period and the ownership share.
        $nightRows = DB::table('reservation_nights')
            ->join('reservations', 'reservations.id', '=', 'reservation_nights.reservation_id')
            // The property lives on the reservation, not the night: a night
            // records which *unit* was occupied, since a long stay can move
            // between units without changing property.
            ->where('reservations.property_id', $propertyId)
            ->whereBetween('reservation_nights.stay_date', [$from->toDateString(), $to->toDateString()])
            // Only bookings that actually earned. An enquiry or a cancelled
            // stay has nights on file and earned nothing.
            ->whereIn('reservations.status', ['confirmed', 'checked_in', 'checked_out'])
            ->select([
                'reservation_nights.stay_date',
                'reservation_nights.rate_amount',
                'reservation_nights.currency',
                'reservation_nights.reservation_id',
                'reservations.confirmation_code',
            ])
            ->orderBy('reservation_nights.stay_date')
            ->get();

        foreach ($nightRows as $night) {
            $date = CarbonImmutable::parse($night->stay_date);

            // The share in force *on this night*. A property sold in March
            // does not retroactively give the buyer February's revenue.
            $share = $this->shareOn($shares, $date);

            if ($share === null) {
                continue;
            }

            $full = Money::of((int) $night->rate_amount, $night->currency ?: $currency);
            $owned = $full->percentage($share);

            if ($owned->isZero()) {
                continue;
            }

            $nights++;
            $reservationIds->push($night->reservation_id);

            $totals['accommodation'] = $totals['accommodation']->add($owned);
            $totals['gross'] = $totals['gross']->add($owned);

            $lines->push($this->line($statement, [
                'category' => OwnerStatementLine::REVENUE,
                'property_id' => $propertyId,
                'reservation_id' => $night->reservation_id,
                'line_date' => $date,
                'description' => sprintf('Accommodation — %s', $night->confirmation_code),
                'amount' => $owned,
                'currency' => $currency,
                'ownership_percentage' => $share,
                'full_amount' => $share < 100.0 ? $full->minorUnits : null,
                'sort_order' => 10,
            ]));
        }

        // Costs the agreement says the owner bears.
        foreach ($this->expensesFor($propertyId, $owner, $from, $to) as $expense) {
            $amount = Money::of(
                (int) $expense->amount + (int) $expense->markup_amount + (int) $expense->tax_amount,
                $expense->currency ?: $currency,
            );

            $share = $this->shareOn($shares, CarbonImmutable::parse($expense->expense_date)) ?? 100.0;
            $owned = $amount->percentage($share);

            if ($owned->isZero()) {
                continue;
            }

            $totals['expenses'] = $totals['expenses']->add($owned);

            $lines->push($this->line($statement, [
                'category' => OwnerStatementLine::EXPENSE,
                'property_id' => $propertyId,
                'expense_id' => $expense->id,
                'line_date' => CarbonImmutable::parse($expense->expense_date),
                'description' => $expense->description,
                'explanation' => (int) $expense->markup_amount > 0
                    ? sprintf(
                        'Includes a handling charge of %s on a cost of %s.',
                        Money::of((int) $expense->markup_amount, $currency)->toDecimal(),
                        Money::of((int) $expense->amount, $currency)->toDecimal(),
                    )
                    : null,
                'amount' => $owned->negate(),
                'currency' => $currency,
                'ownership_percentage' => $share,
                'full_amount' => $share < 100.0 ? $amount->minorUnits : null,
                'sort_order' => 70,
            ]));
        }

        // The fee is computed per property, using that property's own
        // agreement. A portfolio with one differently-negotiated flat is
        // ordinary, and a blanket rate applied across all of them would
        // overcharge on some and undercharge on others — the single most
        // likely way for a statement to be quietly wrong.
        //
        // It is charged on the owner's *share* of the revenue: a 50% owner
        // pays commission on their 50%, not on the whole property's takings.
        $agreement = $owner->agreementFor($propertyId, $to);

        $fee = $this->managementFee($agreement, $totals, $currency);

        if ($fee['amount']->isPositive()) {
            $totals['management_fee'] = $totals['management_fee']->add($fee['amount']);

            $lines->push($this->line($statement, [
                'category' => OwnerStatementLine::MANAGEMENT_FEE,
                'property_id' => $propertyId,
                'line_date' => $to,
                'description' => 'Management fee',
                'explanation' => $fee['explanation'],
                'amount' => $fee['amount']->negate(),
                'currency' => $currency,
                'sort_order' => 60,
            ]));
        }

        return [
            'lines' => $lines,
            'totals' => $totals,
            'nights' => $nights,
            'reservation_ids' => $reservationIds,
        ];
    }

    /**
     * Expenses the owner is liable for, not already on another statement.
     *
     * @return Collection<int, object>
     */
    private function expensesFor(
        string $propertyId,
        Owner $owner,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): Collection {
        return collect(DB::table('expenses')
            ->where('organization_id', $owner->organization_id)
            ->where('property_id', $propertyId)
            ->whereBetween('expense_date', [$from->toDateString(), $to->toDateString()])
            ->where('billable_to', 'owner')
            // Approved only: a draft expense is somebody's intention, not a
            // cost the owner has agreed to bear.
            ->whereIn('status', ['approved', 'paid'])
            // Already settled on an earlier statement. Without this a
            // backdated expense would be billed twice.
            ->whereNull('owner_statement_id')
            ->whereNull('deleted_at')
            ->orderBy('expense_date')
            ->get());
    }

    /**
     * The owner's share of a property on one date.
     */
    private function shareOn(Collection $shares, CarbonImmutable $date): ?float
    {
        foreach ($shares as $share) {
            if ($share->isInForceOn($date)) {
                return (float) $share->ownership_percentage;
            }
        }

        return null;
    }

    /**
     * The management fee for the period.
     *
     * @param  array<string, Money>  $totals
     * @return array{amount: Money, explanation: string}
     */
    private function managementFee(
        ?ManagementAgreement $agreement,
        array $totals,
        string $currency,
    ): array {
        if ($agreement === null) {
            // No agreement means no fee. Inventing a default rate would be
            // charging an owner for terms nobody agreed.
            return [
                'amount' => Money::zero($currency),
                'explanation' => 'No management agreement is in force for this period, so no fee has been charged.',
            ];
        }

        $result = $agreement->commissionFor(
            accommodation: $totals['accommodation'],
            fees: $totals['fees'],
            taxes: $totals['taxes'],
            channelCommission: $totals['channel_commission'],
            paymentFees: $totals['payment_fees'],
        );

        // A fixed monthly fee contributes nothing per booking and is charged
        // once here, which is the only place that knows a period has passed.
        if ($agreement->commission_model === ManagementAgreement::FIXED_MONTHLY) {
            return [
                'amount' => Money::of((int) $agreement->commission_amount, $currency),
                'explanation' => 'A fixed monthly management fee.',
            ];
        }

        return ['amount' => $result['fee'], 'explanation' => $result['explanation']];
    }

    /**
     * What the previous statement left owing in either direction.
     */
    private function openingBalance(
        Owner $owner,
        ?Property $property,
        CarbonImmutable $from,
        string $currency,
    ): Money {
        $previous = OwnerStatement::query()
            ->where('owner_id', $owner->getKey())
            ->when(
                $property !== null,
                fn ($q) => $q->where('property_id', $property->getKey()),
                fn ($q) => $q->whereNull('property_id'),
            )
            ->issued()
            ->where('period_end', '<', $from->toDateString())
            ->orderByDesc('period_end')
            ->first();

        if ($previous === null) {
            return Money::zero($currency);
        }

        // What was left after the payout: a deficit carries forward, and a
        // reserve withheld last time is still the owner's money.
        return Money::of(
            (int) $previous->closing_balance - (int) $previous->payout_amount,
            $previous->currency,
        );
    }

    /**
     * The reserve to withhold, capped at what is actually available.
     */
    private function reserveFor(Owner $owner, Money $closing, string $currency): Money
    {
        $target = Money::of((int) $owner->reserve_amount, $currency);

        if (! $closing->isPositive() || $target->isZero()) {
            return Money::zero($currency);
        }

        return $target->minorUnits > $closing->minorUnits ? $closing : $target;
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    private function openDraft(
        Owner $owner,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?Property $property,
        string $organizationId,
        string $currency,
    ): OwnerStatement {
        $existing = OwnerStatement::query()
            ->where('owner_id', $owner->getKey())
            ->where('property_id', $property?->getKey())
            ->where('period_start', $from->toDateString())
            ->where('period_end', $to->toDateString())
            ->first();

        if ($existing !== null) {
            if (! $existing->isEditable()) {
                throw new \RuntimeException(sprintf(
                    'Statement %s for this period has been issued and cannot be rebuilt. Void it and issue a new one.',
                    $existing->reference,
                ));
            }

            // A rebuild replaces the draft's lines entirely rather than
            // appending to them.
            $existing->lines()->delete();

            return $existing;
        }

        return OwnerStatement::query()->create([
            'organization_id' => $organizationId,
            'owner_id' => $owner->getKey(),
            'property_id' => $property?->getKey(),
            'reference' => $this->sequences->next(
                $organizationId,
                SequenceGenerator::OWNER_STATEMENT,
                'STMT',
                5,
            ),
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'currency' => $currency,
            'status' => OwnerStatement::STATUS_DRAFT,
        ]);
    }

    /**
     * @return Collection<int, string>
     */
    private function propertiesFor(
        Owner $owner,
        ?Property $property,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): Collection {
        if ($property !== null) {
            return collect([$property->getKey()]);
        }

        // Every property they held at any point in the period, not just today:
        // one sold mid-period still earned them money before it went.
        return PropertyOwnership::query()
            ->where('owner_id', $owner->getKey())
            ->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $to->toDateString()))
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $from->toDateString()))
            ->pluck('property_id')
            ->unique()
            ->values();
    }

    /**
     * @return array<string, Money>
     */
    private function emptyTotals(string $currency): array
    {
        return [
            'gross' => Money::zero($currency),
            'accommodation' => Money::zero($currency),
            'fees' => Money::zero($currency),
            'taxes' => Money::zero($currency),
            'channel_commission' => Money::zero($currency),
            'payment_fees' => Money::zero($currency),
            'expenses' => Money::zero($currency),
            'adjustments' => Money::zero($currency),
            'management_fee' => Money::zero($currency),
        ];
    }

    /**
     * @param  array<string, Money>  $a
     * @param  array<string, Money>  $b
     * @return array<string, Money>
     */
    private function addTotals(array $a, array $b): array
    {
        foreach ($a as $key => $value) {
            $a[$key] = $value->add($b[$key]);
        }

        return $a;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function line(OwnerStatement $statement, array $attributes): array
    {
        $amount = $attributes['amount'];

        return [
            'organization_id' => $statement->organization_id,
            'owner_statement_id' => $statement->getKey(),
            'property_id' => $attributes['property_id'] ?? null,
            'reservation_id' => $attributes['reservation_id'] ?? null,
            'expense_id' => $attributes['expense_id'] ?? null,
            'category' => $attributes['category'],
            'line_date' => $attributes['line_date']->toDateString(),
            'description' => $attributes['description'],
            'explanation' => $attributes['explanation'] ?? null,
            'amount' => $amount->minorUnits,
            'currency' => $amount->currency,
            'ownership_percentage' => $attributes['ownership_percentage'] ?? 100,
            'full_amount' => $attributes['full_amount'] ?? null,
            'sort_order' => $attributes['sort_order'] ?? 50,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     */
    private function persistLines(OwnerStatement $statement, Collection $lines): void
    {
        if ($lines->isEmpty()) {
            return;
        }

        foreach ($lines->chunk(500) as $chunk) {
            OwnerStatementLine::query()->insert(
                $chunk->map(fn (array $line): array => $line + [
                    'id' => (string) Str::ulid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all(),
            );
        }
    }

    /**
     * The terms as they stood, copied onto the statement.
     *
     * @return array<string, mixed>|null
     */
    private function snapshot(?ManagementAgreement $agreement): ?array
    {
        if ($agreement === null) {
            return null;
        }

        return $agreement->only([
            'id', 'name', 'commission_model', 'commission_rate', 'commission_amount',
            'commission_tiers', 'commission_on_accommodation', 'commission_on_fees',
            'commission_on_taxes', 'deduct_channel_commission_first',
            'deduct_payment_fees_first', 'owner_pays_cleaning', 'owner_pays_maintenance',
            'owner_pays_supplies', 'maintenance_markup_percent', 'starts_on', 'ends_on',
        ]);
    }
}
