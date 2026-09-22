<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Integrations\DataObjects\OutboundMessage;
use App\Domain\Integrations\Registries\MessageTransportRegistry;
use App\Domain\Reports\Contracts\ReportInterface;
use App\Domain\Reports\DataObjects\ReportResult;
use App\Domain\Reports\Models\SavedReport;
use App\Support\Tenancy\TenantContext;

/**
 * Sends a finished report to the people who asked for it.
 *
 * Goes through the same transport registry as every other outbound message,
 * which means a deployment with no real mail provider records a simulated
 * delivery rather than reporting success. That matters more here than almost
 * anywhere else: a scheduled report failing silently looks exactly like a
 * report with nothing to say, and somebody will spend a month believing
 * occupancy was flat.
 *
 * The report travels as an attachment rather than as a wall of text in the
 * body. A spreadsheet is what the recipient is going to want, and the body
 * carries the summary that tells them whether to open it.
 */
class ReportDelivery
{
    public function __construct(
        private readonly MessageTransportRegistry $transports,
        private readonly ReportExporter $exporter,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Deliver to every recipient on the saved report.
     *
     * @return array{sent: int, failed: int, simulated: bool}
     */
    public function send(SavedReport $saved, ReportInterface $report, ReportResult $result): array
    {
        $recipients = array_values(array_filter((array) $saved->recipients));

        if ($recipients === []) {
            return ['sent' => 0, 'failed' => 0, 'simulated' => false];
        }

        $transport = $this->transports->default();

        $attachment = $this->attachment($saved, $report, $result);
        $body = $this->body($saved, $report, $result, $transport->isLive());

        $sent = 0;
        $failed = 0;

        foreach ($recipients as $email) {
            $outcome = $transport->send(new OutboundMessage(
                body: $body,
                subject: sprintf('%s — %s', $saved->name, $report->name()),
                toEmail: $email,
                organizationId: $this->tenancy->id(),
                attachments: [$attachment],
                context: [
                    'saved_report_id' => $saved->getKey(),
                    'report_key' => $report->key(),
                    'rows' => $result->count(),
                ],
            ));

            $outcome->successful ? $sent++ : $failed++;
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            // Travels back to the caller so the run log can say plainly
            // whether anything actually left the building.
            'simulated' => ! $transport->isLive(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attachment(SavedReport $saved, ReportInterface $report, ReportResult $result): array
    {
        $parameters = $saved->parameters();

        $filename = sprintf(
            '%s-%s-to-%s.%s',
            $report->key(),
            $parameters->from->toDateString(),
            $parameters->to->toDateString(),
            $saved->format === 'json' ? 'json' : 'csv',
        );

        $contents = $saved->format === 'json'
            ? (string) json_encode($this->exporter->toArray($report, $result), JSON_PRETTY_PRINT)
            // The BOM so a spreadsheet opens UTF-8 as UTF-8 rather than as
            // whatever the machine's locale happens to be.
            : "\xEF\xBB\xBF".$this->exporter->toCsv($report, $result);

        return [
            'filename' => $filename,
            'contents' => $contents,
            'mime' => $saved->format === 'json' ? 'application/json' : 'text/csv',
        ];
    }

    private function body(
        SavedReport $saved,
        ReportInterface $report,
        ReportResult $result,
        bool $isLive,
    ): string {
        $parameters = $saved->parameters();

        $lines = [
            $saved->name,
            '',
            sprintf('%s covering %s to %s.',
                $report->name(),
                $parameters->from->toDateString(),
                $parameters->to->toDateString(),
            ),
            sprintf('%d row(s). The full report is attached.', $result->count()),
        ];

        // The caveats travel with the figures. A reader who acts on a number
        // without knowing what it excludes is the failure this is preventing.
        if ($result->notes !== []) {
            $lines[] = '';
            $lines[] = 'Notes:';

            foreach ($result->notes as $note) {
                $lines[] = '  - '.$note;
            }
        }

        if (! $isLive) {
            $lines[] = '';
            $lines[] = 'This message was produced by a local mail transport and was not sent to a real mail server.';
        }

        return implode("\n", $lines);
    }
}
