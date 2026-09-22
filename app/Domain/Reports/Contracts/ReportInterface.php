<?php

declare(strict_types=1);

namespace App\Domain\Reports\Contracts;

use App\Domain\Reports\DataObjects\ReportParameters;
use App\Domain\Reports\DataObjects\ReportResult;

/**
 * One report.
 *
 * Reports are classes rather than stored SQL for a reason that matters: a
 * report describes *rows and columns*, and a stored query would let anybody
 * who can save a report run arbitrary SQL against a multi-tenant database.
 * Every report here is written by hand, scoped by the same tenancy the rest of
 * the platform uses, and takes only the parameters it declares.
 *
 * `columns()` exists so the interface and the exporter can both render a
 * report they have never seen. A report that returns rows without saying what
 * its columns mean is one only its author can display.
 */
interface ReportInterface
{
    /** Stable identifier, used in saved reports and schedules. */
    public function key(): string;

    public function name(): string;

    public function description(): string;

    /** Which module this belongs under, for grouping in the interface. */
    public function category(): string;

    /**
     * The permission a caller needs.
     *
     * Named per report rather than a single "reports.view", because an
     * occupancy report and an owner profit-and-loss are not the same
     * disclosure.
     */
    public function permission(): string;

    /**
     * The columns, in order: key, label, and how to render the value.
     *
     * @return list<array{key: string, label: string, type: string}>
     */
    public function columns(): array;

    /**
     * Which parameters this report accepts, as validation rules.
     *
     * @return array<string, mixed>
     */
    public function parameterRules(): array;

    public function run(ReportParameters $parameters): ReportResult;
}
