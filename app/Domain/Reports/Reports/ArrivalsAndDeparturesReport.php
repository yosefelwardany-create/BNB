<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\DataObjects\ReportParameters;
use App\Domain\Reports\DataObjects\ReportResult;
use App\Domain\Reservations\Enums\ReservationStatus;
use Illuminate\Support\Facades\DB;

/**
 * Who is coming and going.
 *
 * The operational report: printed, or opened on a phone, by whoever is running
 * the day. It is deliberately one row per stay rather than a summary, because
 * the reader's question is "what do I do today" rather than "how did we do".
 *
 * Balance is included because the front desk needs to know before the guest is
 * standing there, and the online check-in flag because a stay that has not
 * completed it needs somebody to chase it.
 */
class ArrivalsAndDeparturesReport extends AbstractReport
{
    public function key(): string
    {
        return 'arrivals_departures';
    }

    public function name(): string
    {
        return 'Arrivals and departures';
    }

    public function description(): string
    {
        return 'Every arrival and departure in the period, with unit, guest count, balance and check-in status.';
    }

    public function category(): string
    {
        return 'Operations';
    }

    public function permission(): string
    {
        return 'reservations.view';
    }

    public function columns(): array
    {
        return [
            $this->column('date', 'Date', 'date'),
            $this->column('movement', 'Movement'),
            $this->column('property_name', 'Property'),
            $this->column('unit_name', 'Unit'),
            $this->column('confirmation_code', 'Booking'),
            $this->column('guest_name', 'Guest'),
            $this->column('guests', 'Guests', 'integer'),
            $this->column('nights', 'Nights', 'integer'),
            $this->column('source', 'Source'),
            $this->column('balance_due', 'Balance due', 'money'),
            $this->column('online_check_in', 'Checked in online', 'boolean'),
        ];
    }

    public function run(ReportParameters $parameters): ReportResult
    {
        $from = $parameters->from->toDateString();
        $to = $parameters->to->toDateString();

        $arrivals = $this->movements($parameters, 'check_in_date', 'Arrival', $from, $to);
        $departures = $this->movements($parameters, 'check_out_date', 'Departure', $from, $to);

        $rows = array_merge($arrivals, $departures);

        // Chronological, then arrivals before departures on the same day: the
        // order somebody works through a morning.
        usort($rows, function (array $a, array $b): int {
            return [$a['date'], $a['movement']] <=> [$b['date'], $b['movement']];
        });

        $unpaid = array_filter(
            $arrivals,
            fn (array $row): bool => $row['balance_due']['amount'] > 0,
        );

        return new ReportResult(
            rows: $rows,
            totals: [
                'date' => 'Total',
                'movement' => null,
                'property_name' => null,
                'unit_name' => null,
                'confirmation_code' => null,
                'guest_name' => null,
                'guests' => array_sum(array_column($arrivals, 'guests')),
                'nights' => null,
                'source' => null,
                'balance_due' => $this->money(
                    array_sum(array_map(
                        fn (array $row): int => $row['balance_due']['amount'],
                        $arrivals,
                    )),
                ),
                'online_check_in' => null,
            ],
            notes: [
                'Cancelled bookings are excluded. A cancelled arrival is not an arrival.',
                'The balance total covers arrivals only, so a departing guest\'s settled account is not double counted.',
            ],
            meta: [
                'currency' => $this->currency(),
                'arrivals' => count($arrivals),
                'departures' => count($departures),
                // The number somebody actually needs off this screen.
                'arrivals_with_balance' => count($unpaid),
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function movements(
        ReportParameters $parameters,
        string $dateColumn,
        string $movement,
        string $from,
        string $to,
    ): array {
        return DB::table('reservations as r')
            ->join('properties as p', 'p.id', '=', 'r.property_id')
            ->leftJoin('units as un', 'un.id', '=', 'r.unit_id')
            ->leftJoin('guests as g', 'g.id', '=', 'r.guest_id')
            ->where('r.organization_id', $this->organizationId())
            ->whereIn('r.status', ReservationStatus::blockingValues())
            ->whereBetween("r.{$dateColumn}", [$from, $to])
            ->when(
                $parameters->propertyIds() !== [],
                fn ($q) => $q->whereIn('r.property_id', $parameters->propertyIds()),
            )
            ->orderBy("r.{$dateColumn}")
            ->selectRaw(
                "r.{$dateColumn} as date, p.name as property_name, un.name as unit_name, "
                .'r.confirmation_code, g.display_name as guest_name, '
                .'r.adults, r.children, r.nights, r.source, r.balance_due, r.currency, '
                .'r.online_check_in_completed_at'
            )
            ->get()
            ->map(fn (object $row): array => [
                'date' => (string) $row->date,
                'movement' => $movement,
                'property_name' => $row->property_name,
                'unit_name' => $row->unit_name,
                'confirmation_code' => $row->confirmation_code,
                'guest_name' => $row->guest_name,
                'guests' => (int) $row->adults + (int) $row->children,
                'nights' => (int) $row->nights,
                'source' => $row->source,
                'balance_due' => $this->money($row->balance_due, $row->currency),
                'online_check_in' => $row->online_check_in_completed_at !== null,
            ])
            ->all();
    }
}
