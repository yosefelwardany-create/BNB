<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\Reports\DataObjects\ReportParameters;
use App\Domain\Reports\DataObjects\ReportResult;

/**
 * What each owner earned, and what it cost them.
 *
 * Built from *issued statements* rather than recomputed from the underlying
 * bookings, and that is the whole design decision. A statement is the figure
 * the owner was actually shown and, once approved, is frozen; recomputing
 * would produce a second set of numbers that drift from it as expenses arrive
 * late and bookings are amended. An owner holding a statement that disagrees
 * with the manager's report is a conversation nobody wins.
 *
 * Draft statements are excluded for the same reason: they are still moving.
 */
class OwnerProfitAndLossReport extends AbstractReport
{
    public function key(): string
    {
        return 'owner_profit_and_loss';
    }

    public function name(): string
    {
        return 'Owner profit and loss';
    }

    public function description(): string
    {
        return 'Revenue, deductions and net due per owner, taken from the statements they were issued.';
    }

    public function category(): string
    {
        return 'Owner accounting';
    }

    public function permission(): string
    {
        return 'owner_statements.view';
    }

    public function columns(): array
    {
        return [
            $this->column('owner_name', 'Owner'),
            $this->column('statements', 'Statements', 'integer'),
            $this->column('nights_sold', 'Nights', 'integer'),
            $this->column('gross_revenue', 'Gross revenue', 'money'),
            $this->column('taxes_collected', 'Tax', 'money'),
            $this->column('channel_commission', 'Channel commission', 'money'),
            $this->column('payment_fees', 'Payment fees', 'money'),
            $this->column('management_fee', 'Management fee', 'money'),
            $this->column('expenses_total', 'Expenses', 'money'),
            $this->column('net_due', 'Net due to owner', 'money'),
            $this->column('paid_out', 'Paid out', 'money'),
        ];
    }

    public function run(ReportParameters $parameters): ReportResult
    {
        $statements = OwnerStatement::query()
            ->with('owner:id,display_name')
            ->issued()
            ->where('period_start', '<=', $parameters->to->toDateString())
            ->where('period_end', '>=', $parameters->from->toDateString())
            ->when(
                $parameters->filter('owner_id') !== null,
                fn ($q) => $q->where('owner_id', $parameters->filter('owner_id')),
            )
            ->get();

        $byOwner = [];

        foreach ($statements as $statement) {
            $ownerId = (string) $statement->owner_id;

            $byOwner[$ownerId] ??= [
                'owner_name' => $statement->owner?->display_name ?? 'Unknown owner',
                'currency' => $statement->currency,
                'statements' => 0,
                'nights_sold' => 0,
                'gross_revenue' => 0,
                'taxes_collected' => 0,
                'channel_commission' => 0,
                'payment_fees' => 0,
                'management_fee' => 0,
                'expenses_total' => 0,
                'net_due' => 0,
                'paid_out' => 0,
            ];

            $byOwner[$ownerId]['statements']++;
            $byOwner[$ownerId]['nights_sold'] += (int) $statement->nights_sold;

            foreach ([
                'gross_revenue', 'taxes_collected', 'channel_commission',
                'payment_fees', 'management_fee', 'expenses_total', 'net_due',
            ] as $field) {
                $byOwner[$ownerId][$field] += (int) $statement->{$field};
            }

            // Only what was actually sent. A statement approved but not yet
            // paid is money owed, not money gone.
            if ($statement->status === OwnerStatement::STATUS_PAID) {
                $byOwner[$ownerId]['paid_out'] += (int) $statement->payout_amount;
            }
        }

        $rows = [];
        $totals = array_fill_keys([
            'statements', 'nights_sold', 'gross_revenue', 'taxes_collected',
            'channel_commission', 'payment_fees', 'management_fee',
            'expenses_total', 'net_due', 'paid_out',
        ], 0);

        foreach ($byOwner as $owner) {
            $currency = $owner['currency'];

            $rows[] = [
                'owner_name' => $owner['owner_name'],
                'statements' => $owner['statements'],
                'nights_sold' => $owner['nights_sold'],
                'gross_revenue' => $this->money($owner['gross_revenue'], $currency),
                'taxes_collected' => $this->money($owner['taxes_collected'], $currency),
                'channel_commission' => $this->money($owner['channel_commission'], $currency),
                'payment_fees' => $this->money($owner['payment_fees'], $currency),
                'management_fee' => $this->money($owner['management_fee'], $currency),
                'expenses_total' => $this->money($owner['expenses_total'], $currency),
                'net_due' => $this->money($owner['net_due'], $currency),
                'paid_out' => $this->money($owner['paid_out'], $currency),
            ];

            foreach (array_keys($totals) as $field) {
                $totals[$field] += $owner[$field];
            }
        }

        // Sorted by what each owner earned, because that is the order somebody
        // reading this cares about.
        usort($rows, fn (array $a, array $b): int => $b['gross_revenue']['amount'] <=> $a['gross_revenue']['amount']);

        $currency = $this->currency();

        return new ReportResult(
            rows: $rows,
            totals: [
                'owner_name' => 'All owners',
                'statements' => $totals['statements'],
                'nights_sold' => $totals['nights_sold'],
                'gross_revenue' => $this->money($totals['gross_revenue'], $currency),
                'taxes_collected' => $this->money($totals['taxes_collected'], $currency),
                'channel_commission' => $this->money($totals['channel_commission'], $currency),
                'payment_fees' => $this->money($totals['payment_fees'], $currency),
                'management_fee' => $this->money($totals['management_fee'], $currency),
                'expenses_total' => $this->money($totals['expenses_total'], $currency),
                'net_due' => $this->money($totals['net_due'], $currency),
                'paid_out' => $this->money($totals['paid_out'], $currency),
            ],
            notes: [
                'Taken from issued statements, not recomputed. These are the figures each owner was actually shown.',
                'Draft statements are excluded: their figures can still change.',
                'A statement whose period straddles the report window is included whole. Owner periods are monthly and are not split.',
                'The totals row adds across owners who may be paid in different currencies; read the per-owner rows where that matters.',
            ],
            meta: ['currency' => $currency],
        );
    }
}
