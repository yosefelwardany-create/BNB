<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\DataObjects\ReportParameters;
use App\Domain\Reports\DataObjects\ReportResult;
use Illuminate\Support\Facades\DB;

/**
 * Whether the work got done, and on time.
 *
 * "Completed" alone flatters: a clean finished at four when the guest arrived
 * at three was completed and was also a failure. So lateness is measured
 * against the due time rather than counted as a binary, and the two are
 * reported side by side.
 */
class HousekeepingReport extends AbstractReport
{
    public function key(): string
    {
        return 'housekeeping';
    }

    public function name(): string
    {
        return 'Housekeeping and maintenance performance';
    }

    public function description(): string
    {
        return 'Tasks by kind and assignee, with completion rates and how often work finished after it was due.';
    }

    public function category(): string
    {
        return 'Operations';
    }

    public function permission(): string
    {
        return 'tasks.view';
    }

    public function columns(): array
    {
        return [
            $this->column('assignee', 'Assigned to'),
            $this->column('kind', 'Kind'),
            $this->column('total', 'Tasks', 'integer'),
            $this->column('completed', 'Completed', 'integer'),
            $this->column('cancelled', 'Cancelled', 'integer'),
            $this->column('outstanding', 'Outstanding', 'integer'),
            $this->column('completed_late', 'Finished late', 'integer'),
            $this->column('on_time_rate', 'On time', 'percentage'),
        ];
    }

    public function run(ReportParameters $parameters): ReportResult
    {
        $rows = DB::table('tasks as t')
            ->leftJoin('users as u', 'u.id', '=', 't.assigned_to_id')
            ->where('t.organization_id', $this->organizationId())
            ->whereNull('t.deleted_at')
            ->whereBetween(DB::raw('t.due_at::date'), [
                $parameters->from->toDateString(),
                $parameters->to->toDateString(),
            ])
            ->when(
                $parameters->propertyIds() !== [],
                fn ($q) => $q->whereIn('t.property_id', $parameters->propertyIds()),
            )
            ->groupBy('t.assigned_to_id', 'u.first_name', 'u.last_name', 't.kind')
            ->selectRaw(
                "coalesce(u.first_name || ' ' || u.last_name, 'Unassigned') as assignee, t.kind, "
                .'count(*) as total, '
                ."count(*) filter (where t.status = 'completed') as completed, "
                ."count(*) filter (where t.status = 'cancelled') as cancelled, "
                // Late means finished after it was due — not merely finished.
                ."count(*) filter (where t.status = 'completed' and t.completed_at > t.due_at) as completed_late"
            )
            ->orderByDesc('total')
            ->get();

        $totals = ['total' => 0, 'completed' => 0, 'cancelled' => 0, 'completed_late' => 0];

        $mapped = $rows->map(function (object $row) use (&$totals): array {
            $total = (int) $row->total;
            $completed = (int) $row->completed;
            $cancelled = (int) $row->cancelled;
            $late = (int) $row->completed_late;

            $totals['total'] += $total;
            $totals['completed'] += $completed;
            $totals['cancelled'] += $cancelled;
            $totals['completed_late'] += $late;

            return [
                'assignee' => $row->assignee,
                'kind' => $row->kind,
                'total' => $total,
                'completed' => $completed,
                'cancelled' => $cancelled,
                // Neither done nor abandoned: the ones somebody has to chase.
                'outstanding' => $total - $completed - $cancelled,
                'completed_late' => $late,
                // Measured against work that was actually completed, so a
                // cleaner with outstanding work is not credited with being
                // punctual about it.
                'on_time_rate' => $this->percentage($completed - $late, $completed),
            ];
        })->all();

        return new ReportResult(
            rows: $mapped,
            totals: [
                'assignee' => 'Everyone',
                'kind' => null,
                'total' => $totals['total'],
                'completed' => $totals['completed'],
                'cancelled' => $totals['cancelled'],
                'outstanding' => $totals['total'] - $totals['completed'] - $totals['cancelled'],
                'completed_late' => $totals['completed_late'],
                'on_time_rate' => $this->percentage(
                    $totals['completed'] - $totals['completed_late'],
                    $totals['completed'],
                ),
            ],
            notes: [
                'Counted by due date, so a task created late still appears against the day it was needed.',
                'Late means finished after its due time. A clean finished at four for a three o\'clock arrival was completed and was also a failure.',
                'On-time rate is measured against completed work only; outstanding tasks are reported separately rather than counted as punctual.',
            ],
        );
    }
}
