<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\DataObjects\ReportParameters;
use App\Domain\Reports\DataObjects\ReportResult;
use App\Domain\Reservations\Enums\ReservationStatus;
use Illuminate\Support\Facades\DB;

/**
 * What is owed to whom, and what somebody else already paid.
 *
 * The distinction this report exists to make is between tax we collected and
 * must remit, and tax a channel collected and remits itself. Several OTAs
 * remit occupancy tax directly in several jurisdictions; counting that as our
 * liability would have an operator paying it twice, and omitting the
 * distinction entirely would have them unable to explain the gap between what
 * guests paid and what they owe.
 *
 * So both columns are reported, always, even when one is zero.
 */
class TaxLiabilityReport extends AbstractReport
{
    public function key(): string
    {
        return 'tax_liability';
    }

    public function name(): string
    {
        return 'Tax liability';
    }

    public function description(): string
    {
        return 'Tax charged per rule, split between what we collected and must remit and what a channel remitted on our behalf.';
    }

    public function category(): string
    {
        return 'Financials';
    }

    public function permission(): string
    {
        return 'taxes.manage';
    }

    public function columns(): array
    {
        return [
            $this->column('tax_name', 'Tax'),
            $this->column('tax_code', 'Code'),
            $this->column('jurisdiction', 'Jurisdiction'),
            $this->column('bookings', 'Bookings', 'integer'),
            $this->column('collected_by_us', 'We collected', 'money'),
            $this->column('collected_by_channel', 'Channel collected', 'money'),
            $this->column('total_charged', 'Total charged', 'money'),
            $this->column('remittance_reference', 'Remittance reference'),
        ];
    }

    public function run(ReportParameters $parameters): ReportResult
    {
        $currency = $this->currency();

        // By stay date rather than booking date: a tax return covers nights
        // that happened, not deposits that were taken.
        $rows = DB::table('reservation_charges as rc')
            ->join('reservations as r', 'r.id', '=', 'rc.reservation_id')
            ->leftJoin('tax_rules as t', 't.id', '=', 'rc.tax_rule_id')
            ->leftJoin('channel_accounts as ca', 'ca.id', '=', 'r.channel_account_id')
            ->where('rc.organization_id', $this->organizationId())
            ->where('rc.kind', 'tax')
            ->whereIn('r.status', ReservationStatus::revenueValues())
            ->whereBetween('r.check_in_date', [
                $parameters->from->toDateString(),
                $parameters->to->toDateString(),
            ])
            ->when(
                $parameters->propertyIds() !== [],
                fn ($q) => $q->whereIn('r.property_id', $parameters->propertyIds()),
            )
            ->groupBy('t.id', 't.name', 't.code', 't.country_code', 't.region', 't.city', 't.remittance_reference')
            ->selectRaw(
                't.name as tax_name, t.code as tax_code, '
                .'t.country_code, t.region, t.city, t.remittance_reference, '
                .'count(distinct r.id) as bookings, '
                .'sum(rc.amount) as total_charged, '
                // A channel that collects the guest's money and remits the tax
                // itself. `collects_payment` is the fact that decides it.
                .'sum(case when ca.collects_payment then rc.amount else 0 end) as by_channel, '
                .'sum(case when ca.collects_payment then 0 else rc.amount end) as by_us'
            )
            ->orderByDesc('total_charged')
            ->get();

        $byUs = 0;
        $byChannel = 0;
        $total = 0;
        $bookings = 0;

        $mapped = $rows->map(function (object $row) use ($currency, &$byUs, &$byChannel, &$total, &$bookings): array {
            $byUs += (int) $row->by_us;
            $byChannel += (int) $row->by_channel;
            $total += (int) $row->total_charged;
            $bookings += (int) $row->bookings;

            return [
                // A charge whose tax rule has since been deleted still has to
                // appear: the money was collected either way, and a hole in a
                // tax report is worse than an unnamed row.
                'tax_name' => $row->tax_name ?? 'Unattributed tax',
                'tax_code' => $row->tax_code,
                'jurisdiction' => trim(implode(', ', array_filter([
                    $row->city, $row->region, $row->country_code,
                ]))) ?: null,
                'bookings' => (int) $row->bookings,
                'collected_by_us' => $this->money($row->by_us, $currency),
                'collected_by_channel' => $this->money($row->by_channel, $currency),
                'total_charged' => $this->money($row->total_charged, $currency),
                'remittance_reference' => $row->remittance_reference,
            ];
        })->all();

        return new ReportResult(
            rows: $mapped,
            totals: [
                'tax_name' => 'All taxes',
                'tax_code' => null,
                'jurisdiction' => null,
                'bookings' => $bookings,
                'collected_by_us' => $this->money($byUs, $currency),
                'collected_by_channel' => $this->money($byChannel, $currency),
                'total_charged' => $this->money($total, $currency),
                'remittance_reference' => null,
            ],
            notes: [
                'Counted by arrival date, because a tax return covers nights that happened rather than deposits that were taken.',
                'Only the "we collected" column is owed by this business. The channel column was remitted by the channel that took the guest\'s money.',
                'Cancelled bookings are excluded. Tax on a cancellation fee, where one was charged, is not.',
            ],
            meta: ['currency' => $currency],
        );
    }
}
