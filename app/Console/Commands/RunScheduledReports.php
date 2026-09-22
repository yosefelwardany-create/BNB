<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\Services\Notifier;
use App\Domain\Organization\Models\Organization;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\Services\ReportDelivery;
use App\Domain\Reports\Services\ReportRunner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Delivers saved reports on their schedule.
 *
 * Each report runs as the person who saved it and is refused if that person no
 * longer holds the permission it requires. Somebody moved off the finance team
 * should stop receiving the finance report, and a schedule that keeps
 * delivering after its author lost access is a privilege escalation with a
 * delay built in.
 *
 * A failure is recorded on the report and the run continues. One broken
 * schedule must not stop every other report in the organization — and the
 * error being visible on the report itself is how somebody notices, rather
 * than through a silence they mistake for "nothing to say this month".
 */
class RunScheduledReports extends Command
{
    protected $signature = 'reports:run-scheduled {--organization= : Restrict to one organization}';

    protected $description = 'Run and deliver saved reports whose schedule is due';

    public function handle(
        ReportRunner $runner,
        ReportDelivery $delivery,
        Notifier $notifier,
        TenantContext $tenancy,
    ): int {
        $organizations = $tenancy->withoutScope(function (): Collection {
            $query = Organization::query();

            if ($this->option('organization') !== null) {
                $query->whereKey($this->option('organization'));
            }

            return $query->get();
        });

        $delivered = 0;
        $failed = 0;

        foreach ($organizations as $organization) {
            $tenancy->runAs($organization, function () use (
                $runner, $delivery, $notifier, &$delivered, &$failed
            ): void {
                SavedReport::query()
                    ->due()
                    ->with('creator')
                    ->chunkById(50, function (Collection $reports) use (
                        $runner, $delivery, $notifier, &$delivered, &$failed
                    ): void {
                        foreach ($reports as $saved) {
                            $this->deliver($saved, $runner, $delivery, $notifier, $delivered, $failed);
                        }
                    });
            });
        }

        $this->components->info(sprintf(
            'Scheduled reports: %d delivered, %d failed.',
            $delivered,
            $failed,
        ));

        return self::SUCCESS;
    }

    private function deliver(
        SavedReport $saved,
        ReportRunner $runner,
        ReportDelivery $delivery,
        Notifier $notifier,
        int &$delivered,
        int &$failed,
    ): void {
        try {
            ['report' => $report, 'result' => $result] = $runner->runSaved($saved);
        } catch (\Throwable $exception) {
            $failed++;

            // Recorded on the report rather than only logged, so the person
            // relying on it can see why it stopped arriving.
            $runner->markRun($saved, $exception->getMessage());

            $this->components->error(sprintf('%s: %s', $saved->name, $exception->getMessage()));

            return;
        }

        // Sent through the same transport registry as every other outbound
        // message, so a deployment without a real mail provider records a
        // simulated delivery rather than reporting success. That matters more
        // here than almost anywhere: a report failing silently looks exactly
        // like a report with nothing to say.
        $outcome = $delivery->send($saved, $report, $result);

        // The person who set it up also gets a notification in the product,
        // which is where they will look when they wonder whether it ran.
        if ($saved->creator !== null) {
            $notifier->notify(
                $saved->creator,
                'report.delivered',
                sprintf('%s is ready', $saved->name),
                $this->summary($saved, $report->name(), $result->count(), $outcome),
                subject: $saved,
            );
        }

        // A run where nothing could be sent is recorded as an error rather
        // than as a success, because from the recipient's side it is one.
        $error = $outcome['sent'] === 0 && $outcome['failed'] > 0
            ? sprintf('None of the %d recipient(s) could be reached.', $outcome['failed'])
            : null;

        $runner->markRun($saved, $error);

        $error === null ? $delivered++ : $failed++;
    }

    /**
     * @param  array{sent: int, failed: int, simulated: bool}  $outcome
     */
    private function summary(SavedReport $saved, string $reportName, int $rows, array $outcome): string
    {
        $summary = sprintf(
            '%s: %d row(s), sent to %d recipient(s).',
            $reportName,
            $rows,
            $outcome['sent'],
        );

        if ($outcome['failed'] > 0) {
            $summary .= sprintf(' %d could not be reached.', $outcome['failed']);
        }

        // Never left to be assumed: a "delivered" notification about a mail
        // that went to a local log would be the platform lying to its
        // operator.
        if ($outcome['simulated']) {
            $summary .= ' Delivery was simulated: no live mail transport is configured.';
        }

        return $summary;
    }
}
