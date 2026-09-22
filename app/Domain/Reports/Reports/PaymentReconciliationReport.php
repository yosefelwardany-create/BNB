<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\DataObjects\ReportParameters;
use App\Domain\Reports\DataObjects\ReportResult;
use Illuminate\Support\Facades\DB;

/**
 * What the bank should show.
 *
 * The report somebody opens at month end holding a bank statement, and its
 * whole job is to make the two agree. Three distinctions decide whether it
 * can:
 *
 * **Gross is not net.** A processor keeps a fee, and the bank shows what
 * arrived, not what was charged. Both are reported, and so is the difference.
 *
 * **Collected by us is not the same as paid.** A channel that takes the
 * guest's money itself produces real revenue that never touched our bank, and
 * including it here would leave an operator hunting a deposit that was never
 * going to arrive.
 *
 * **Simulated is not real.** Where no live processor is configured, the
 * platform records payments against a local implementation. Those rows are
 * counted separately and labelled, because a reconciliation report that
 * silently includes them is a reconciliation report that cannot be trusted at
 * all.
 */
class PaymentReconciliationReport extends AbstractReport
{
    public function key(): string
    {
        return 'payment_reconciliation';
    }

    public function name(): string
    {
        return 'Payment reconciliation';
    }

    public function description(): string
    {
        return 'Captures, refunds, processor fees and net settlement by day and provider, for matching against a bank statement.';
    }

    public function category(): string
    {
        return 'Financials';
    }

    public function permission(): string
    {
        return 'financials.view';
    }

    public function columns(): array
    {
        return [
            $this->column('date', 'Date', 'date'),
            $this->column('provider', 'Provider'),
            $this->column('method', 'Method'),
            $this->column('payments', 'Payments', 'integer'),
            $this->column('gross_captured', 'Gross captured', 'money'),
            $this->column('processor_fees', 'Processor fees', 'money'),
            $this->column('refunded', 'Refunded', 'money'),
            $this->column('net_settled', 'Net to bank', 'money'),
            $this->column('is_simulated', 'Simulated', 'boolean'),
        ];
    }

    public function run(ReportParameters $parameters): ReportResult
    {
        $currency = $this->currency();

        $rows = DB::table('payments as p')
            ->where('p.organization_id', $this->organizationId())
            // Only money that actually reached us. A channel-collected payment
            // is real revenue that never touched the bank, and hunting for it
            // on a statement is a wasted afternoon.
            ->where('p.is_collected_by_us', true)
            ->whereNotNull('p.captured_at')
            ->whereBetween(DB::raw('p.captured_at::date'), [
                $parameters->from->toDateString(),
                $parameters->to->toDateString(),
            ])
            ->when(
                $parameters->propertyIds() !== [],
                fn ($q) => $q->whereIn('p.property_id', $parameters->propertyIds()),
            )
            ->groupBy(DB::raw('p.captured_at::date'), 'p.provider', 'p.method', 'p.is_simulated')
            ->selectRaw(
                'p.captured_at::date as date, p.provider, p.method, p.is_simulated, '
                .'count(*) as payments, '
                .'sum(p.captured_amount) as gross_captured, '
                .'sum(p.fee_amount) as processor_fees, '
                .'sum(p.refunded_amount) as refunded'
            )
            ->orderBy('date')
            ->orderBy('p.provider')
            ->get();

        $gross = 0;
        $fees = 0;
        $refunded = 0;
        $payments = 0;
        $simulatedGross = 0;

        $mapped = $rows->map(function (object $row) use (
            $currency, &$gross, &$fees, &$refunded, &$payments, &$simulatedGross
        ): array {
            $rowGross = (int) $row->gross_captured;
            $rowFees = (int) $row->processor_fees;
            $rowRefunded = (int) $row->refunded;

            $gross += $rowGross;
            $fees += $rowFees;
            $refunded += $rowRefunded;
            $payments += (int) $row->payments;

            if ($row->is_simulated) {
                $simulatedGross += $rowGross;
            }

            return [
                'date' => (string) $row->date,
                'provider' => $row->provider ?? 'external',
                'method' => $row->method,
                'payments' => (int) $row->payments,
                'gross_captured' => $this->money($rowGross, $currency),
                'processor_fees' => $this->money($rowFees, $currency),
                'refunded' => $this->money($rowRefunded, $currency),
                // What the bank should show: gross, less what the processor
                // kept, less what went back to guests.
                'net_settled' => $this->money($rowGross - $rowFees - $rowRefunded, $currency),
                'is_simulated' => (bool) $row->is_simulated,
            ];
        })->all();

        $notes = [
            'Counted by capture date, because that is when the money moved.',
            'Channel-collected payments are excluded: they are real revenue that never reached this bank account.',
            'Net to bank is gross less processor fees less refunds.',
        ];

        // Said loudly when it is true, and not mentioned when it is not.
        if ($simulatedGross > 0) {
            $notes[] = sprintf(
                'WARNING: %s of this total was processed by a simulated payment provider and did not move real money. '
                .'Rows are marked in the Simulated column.',
                $this->money($simulatedGross, $currency)['formatted'],
            );
        }

        return new ReportResult(
            rows: $mapped,
            totals: [
                'date' => 'Total',
                'provider' => null,
                'method' => null,
                'payments' => $payments,
                'gross_captured' => $this->money($gross, $currency),
                'processor_fees' => $this->money($fees, $currency),
                'refunded' => $this->money($refunded, $currency),
                'net_settled' => $this->money($gross - $fees - $refunded, $currency),
                'is_simulated' => $simulatedGross > 0,
            ],
            notes: $notes,
            meta: [
                'currency' => $currency,
                'simulated_gross' => $this->money($simulatedGross, $currency),
            ],
        );
    }
}
