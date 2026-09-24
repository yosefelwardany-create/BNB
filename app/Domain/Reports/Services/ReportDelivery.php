<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Reports\Contracts\ReportInterface;
use App\Domain\Reports\DataObjects\ReportArtifact;
use App\Domain\Reports\DataObjects\ReportDeliveryOutcome;
use App\Domain\Reports\DataObjects\ReportResult;
use App\Domain\Reports\Models\SavedReport;

/**
 * Sends a finished report everywhere it was asked to go.
 *
 * Email used to be the only answer, and it is the wrong only answer: an
 * operator who wants last month's occupancy in their own system was told to
 * receive an attachment and forward it by hand. A report can now go to several
 * destinations in one run — emailed to the accountant, posted to a warehouse,
 * kept as a file so somebody can answer "what did this say in March?"
 *
 * Two properties this holds on to:
 *
 * **The report is rendered once.** Every destination gets the same bytes, so a
 * report emailed and posted in the same run cannot differ because a booking
 * landed between them.
 *
 * **A failing destination does not take the others down.** A webhook receiver
 * that is offline must not stop the email, and the run records which one
 * failed and why — because a scheduled report failing silently looks exactly
 * like a report with nothing to say, and somebody will spend a month believing
 * occupancy was flat.
 */
class ReportDelivery
{
    public function __construct(
        private readonly ReportDestinationRegistry $destinations,
        private readonly ReportExporter $exporter,
    ) {}

    /**
     * Deliver to every destination on the saved report.
     *
     * @return array{sent: int, failed: int, simulated: bool, deliveries: list<array<string, mixed>>}
     */
    public function send(SavedReport $saved, ReportInterface $report, ReportResult $result): array
    {
        $targets = $saved->deliveryTargets();

        if ($targets === []) {
            return ['sent' => 0, 'failed' => 0, 'simulated' => false, 'deliveries' => []];
        }

        $artifact = $this->render($saved, $report, $result);

        $outcomes = [];

        foreach ($targets as $target) {
            $outcomes[] = $this->deliverTo($saved, $report, $artifact, $target);
        }

        $sent = 0;
        $failed = 0;
        $simulated = false;

        foreach ($outcomes as $outcome) {
            $outcome->successful ? $sent++ : $failed++;

            // Any destination that did not really reach anybody makes the run
            // as a whole not fully real, and the notification says so.
            $simulated = $simulated || ($outcome->successful && $outcome->simulated);
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'simulated' => $simulated,
            'deliveries' => array_map(
                static fn (ReportDeliveryOutcome $outcome): array => $outcome->toArray(),
                $outcomes,
            ),
        ];
    }

    /**
     * Render the report once, in the format the saved report asked for.
     */
    public function render(SavedReport $saved, ReportInterface $report, ReportResult $result): ReportArtifact
    {
        $parameters = $saved->parameters();
        $isJson = $saved->format === 'json';

        return new ReportArtifact(
            filename: sprintf(
                '%s-%s-to-%s.%s',
                $report->key(),
                $parameters->from->toDateString(),
                $parameters->to->toDateString(),
                $isJson ? 'json' : 'csv',
            ),
            contents: $isJson
                ? (string) json_encode($this->exporter->toArray($report, $result), JSON_PRETTY_PRINT)
                // The BOM so a spreadsheet opens UTF-8 as UTF-8 rather than as
                // whatever the machine's locale happens to be.
                : "\xEF\xBB\xBF".$this->exporter->toCsv($report, $result),
            mimeType: $isJson ? 'application/json' : 'text/csv',
            format: $isJson ? 'json' : 'csv',
            rowCount: $result->count(),
            notes: $result->notes,
        );
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function deliverTo(
        SavedReport $saved,
        ReportInterface $report,
        ReportArtifact $artifact,
        array $target,
    ): ReportDeliveryOutcome {
        $type = (string) ($target['type'] ?? '');

        if (! $this->destinations->has($type)) {
            return ReportDeliveryOutcome::failed(
                $type ?: 'unknown',
                'nowhere',
                sprintf('No destination is registered for [%s].', $type),
            );
        }

        $destination = $this->destinations->make($type);

        try {
            return $destination->deliver($saved, $report, $artifact, $target);
        } catch (\Throwable $exception) {
            // A destination is not allowed to abandon the rest of the run. One
            // that throws is a bug in that destination, recorded where the
            // person relying on the report will see it.
            return ReportDeliveryOutcome::failed(
                $type,
                (string) ($target['url'] ?? $target['target'] ?? $destination->displayName()),
                $exception->getMessage(),
            );
        }
    }
}
