<?php

declare(strict_types=1);

namespace App\Domain\Reports\DataObjects;

/**
 * What a report produced.
 *
 * Rows, plus a totals row kept separate from them. The separation matters:
 * folding totals into the rows makes a report that cannot be sorted, filtered
 * or paginated without the total wandering into the middle of the data, and
 * every spreadsheet export then has a phantom row somebody sums twice.
 *
 * `notes` is for caveats the reader needs — a period that includes unconfirmed
 * bookings, a currency conversion that used today's rate. A report that
 * quietly rounds, estimates or excludes something is a report that will be
 * acted on wrongly.
 */
final class ReportResult
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $totals
     * @param  list<string>  $notes
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $totals = [],
        public readonly array $notes = [],
        public readonly array $meta = [],
    ) {}

    public function count(): int
    {
        return count($this->rows);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'totals' => $this->totals,
            'notes' => $this->notes,
            'meta' => $this->meta,
        ];
    }
}
