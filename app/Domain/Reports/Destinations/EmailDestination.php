<?php

declare(strict_types=1);

namespace App\Domain\Reports\Destinations;

use App\Domain\Integrations\DataObjects\OutboundMessage;
use App\Domain\Integrations\Registries\MessageTransportRegistry;
use App\Domain\Reports\Contracts\ReportDestinationInterface;
use App\Domain\Reports\Contracts\ReportInterface;
use App\Domain\Reports\DataObjects\ReportArtifact;
use App\Domain\Reports\DataObjects\ReportDeliveryOutcome;
use App\Domain\Reports\Models\SavedReport;
use App\Support\Tenancy\TenantContext;

/**
 * A report as an attachment on an email.
 *
 * The original destination and still the common one. It goes through the same
 * transport registry as every other outbound message, so a deployment with no
 * real mail provider records a simulated delivery rather than reporting
 * success.
 *
 * The report travels as an attachment rather than as a wall of text in the
 * body: a spreadsheet is what the recipient wants, and the body carries the
 * summary that tells them whether to open it.
 */
class EmailDestination implements ReportDestinationInterface
{
    public function __construct(
        private readonly MessageTransportRegistry $transports,
        private readonly TenantContext $tenancy,
    ) {}

    public function key(): string
    {
        return 'email';
    }

    public function displayName(): string
    {
        return 'Email';
    }

    public function describe(): string
    {
        return 'Sends the report as an attachment. Needs one or more email addresses.';
    }

    public function problemsWith(array $config): array
    {
        $recipients = $this->recipientsFrom($config);

        if ($recipients === []) {
            return ['At least one email address is needed.'];
        }

        $problems = [];

        foreach ($recipients as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $problems[] = sprintf('[%s] is not an email address.', $address);
            }
        }

        return $problems;
    }

    public function deliver(
        SavedReport $saved,
        ReportInterface $report,
        ReportArtifact $artifact,
        array $config,
    ): ReportDeliveryOutcome {
        $recipients = $this->recipientsFrom($config)
            // A destination configured without its own list falls back to the
            // report's recipients, which is where every schedule saved before
            // destinations existed keeps them.
            ?: array_values(array_filter((array) $saved->recipients));

        if ($recipients === []) {
            return ReportDeliveryOutcome::failed(
                $this->key(),
                'nobody',
                'This report has no email recipients, so it was produced and sent to nobody.',
            );
        }

        $transport = $this->transports->default();

        $attachment = [
            'filename' => $artifact->filename,
            'contents' => $artifact->contents,
            'mime' => $artifact->mimeType,
        ];

        $sent = 0;
        $failed = [];

        foreach ($recipients as $address) {
            $outcome = $transport->send(new OutboundMessage(
                body: $this->body($saved, $report, $artifact, $transport->isLive()),
                subject: sprintf('%s — %s', $saved->name, $report->name()),
                toEmail: $address,
                organizationId: $this->tenancy->id(),
                attachments: [$attachment],
                context: [
                    'saved_report_id' => $saved->getKey(),
                    'report_key' => $report->key(),
                    'rows' => $artifact->rowCount,
                ],
            ));

            $outcome->successful ? $sent++ : $failed[] = $address;
        }

        $target = implode(', ', $recipients);

        if ($sent === 0) {
            return ReportDeliveryOutcome::failed(
                $this->key(),
                $target,
                sprintf('None of the %d recipient(s) could be reached.', count($recipients)),
            );
        }

        if (! $transport->isLive()) {
            return ReportDeliveryOutcome::recordedLocally(
                $this->key(),
                $target,
                (string) ($transport->simulationReason()
                    ?? 'No live mail transport is configured, so nothing was sent.'),
                ['sent' => $sent],
            );
        }

        return ReportDeliveryOutcome::delivered($this->key(), $target, [
            'sent' => $sent,
            'unreachable' => $failed,
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function recipientsFrom(array $config): array
    {
        $recipients = $config['recipients'] ?? [];

        if (is_string($recipients)) {
            $recipients = [$recipients];
        }

        return array_values(array_filter(
            array_map(
                static fn (mixed $value): string => trim((string) $value),
                is_array($recipients) ? $recipients : [],
            ),
            static fn (string $value): bool => $value !== '',
        ));
    }

    private function body(
        SavedReport $saved,
        ReportInterface $report,
        ReportArtifact $artifact,
        bool $isLive,
    ): string {
        $parameters = $saved->parameters();

        $lines = [
            $saved->name,
            '',
            sprintf(
                '%s covering %s to %s.',
                $report->name(),
                $parameters->from->toDateString(),
                $parameters->to->toDateString(),
            ),
            sprintf('%d row(s). The full report is attached.', $artifact->rowCount),
        ];

        // The caveats travel with the figures. A reader who acts on a number
        // without knowing what it excludes is the failure this is preventing.
        if ($artifact->notes !== []) {
            $lines[] = '';
            $lines[] = 'Notes:';

            foreach ($artifact->notes as $note) {
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
