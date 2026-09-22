<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Reports\Contracts\ReportInterface;
use App\Domain\Reports\DataObjects\ReportResult;

/**
 * Renders a report as something a person can take away.
 *
 * Two details here are the difference between an export that opens correctly
 * and one that produces a support ticket.
 *
 * **Money exports as a plain decimal, not as the value object.** A spreadsheet
 * cannot sum `{amount: 1250, currency: "EUR"}`, and the currency belongs in
 * the column header where it is stated once rather than repeated on every row.
 *
 * **A cell beginning with `=`, `+`, `-` or `@` is prefixed.** Spreadsheet
 * software treats those as formulas, so a guest named `=cmd|...` in a CSV is a
 * remote code execution against whoever opens it. The value is preserved, not
 * stripped: it is the guest's name, and mangling it silently is its own bug.
 */
class ReportExporter
{
    /**
     * The rows as CSV, with a header row and a totals row.
     */
    public function toCsv(ReportInterface $report, ReportResult $result): string
    {
        $handle = fopen('php://temp', 'r+');

        $columns = $report->columns();

        fputcsv($handle, array_map(
            fn (array $column): string => $this->header($column, $result),
            $columns,
        ));

        foreach ($result->rows as $row) {
            fputcsv($handle, $this->cells($columns, $row));
        }

        if ($result->totals !== []) {
            // A blank line before it, so the totals are visibly not data. A
            // spreadsheet that sorts the rows would otherwise sort the total
            // into the middle of them.
            fputcsv($handle, array_fill(0, count($columns), ''));
            fputcsv($handle, $this->cells($columns, $result->totals));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * The full result as structured data, for an API consumer.
     *
     * @return array<string, mixed>
     */
    public function toArray(ReportInterface $report, ReportResult $result): array
    {
        return [
            'report' => [
                'key' => $report->key(),
                'name' => $report->name(),
                'description' => $report->description(),
                'category' => $report->category(),
                'columns' => $report->columns(),
            ],
        ] + $result->toArray();
    }

    /**
     * @param  array{key: string, label: string, type: string}  $column
     */
    private function header(array $column, ReportResult $result): string
    {
        $currency = $result->meta['currency'] ?? null;

        // The currency is stated once, in the header, rather than repeated on
        // every row where a spreadsheet would refuse to sum it.
        if ($column['type'] === 'money' && $currency !== null) {
            return sprintf('%s (%s)', $column['label'], $currency);
        }

        if ($column['type'] === 'percentage') {
            return $column['label'].' (%)';
        }

        return $column['label'];
    }

    /**
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function cells(array $columns, array $row): array
    {
        $cells = [];

        foreach ($columns as $column) {
            $cells[] = $this->cell($row[$column['key']] ?? null, $column['type']);
        }

        return $cells;
    }

    private function cell(mixed $value, string $type): string
    {
        if ($value === null) {
            return '';
        }

        if ($type === 'money') {
            // The decimal alone: a spreadsheet can add these up.
            return is_array($value) ? (string) ($value['formatted'] ?? '') : (string) $value;
        }

        if ($type === 'boolean') {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return $this->neutralise((string) $value);
    }

    /**
     * Stop a spreadsheet treating a value as a formula.
     *
     * A leading `=`, `+`, `-` or `@` makes Excel and its imitators evaluate the
     * cell, and a crafted value can invoke external programs on the machine of
     * whoever opens the file. The value is preserved behind a leading
     * apostrophe rather than stripped: it is somebody's name or reference, and
     * quietly mangling it is its own kind of wrong.
     */
    private function neutralise(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'".$value
            : $value;
    }
}
