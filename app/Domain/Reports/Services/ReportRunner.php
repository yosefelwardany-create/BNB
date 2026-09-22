<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Reports\Contracts\ReportInterface;
use App\Domain\Reports\DataObjects\ReportParameters;
use App\Domain\Reports\DataObjects\ReportResult;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use Cron\CronExpression;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Runs reports, and keeps the schedule honest.
 *
 * The permission check lives here rather than only in the controller, because
 * a scheduled report runs with no request and no signed-in user. Without a
 * check at this level, saving a report as somebody who can see owner
 * statements and then scheduling it would deliver those figures to anybody on
 * the recipient list — a privilege escalation with a delay built in.
 *
 * So a scheduled report is run *as its creator*, and refuses if that person no
 * longer holds the permission the report requires. Somebody losing access to
 * financial data should stop receiving financial reports, not keep receiving
 * them because they once set one up.
 */
class ReportRunner
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly AccessControl $access,
    ) {}

    /**
     * Run a report for a user, checking they may see it.
     */
    public function run(string $key, ReportParameters $parameters, User $user): ReportResult
    {
        $report = $this->registry->make($key);

        $this->assertPermitted($report, $user);

        return $report->run($parameters);
    }

    /**
     * Run a saved report as the person who saved it.
     *
     * @return array{report: ReportInterface, result: ReportResult}
     */
    public function runSaved(SavedReport $saved): array
    {
        $report = $this->registry->make($saved->report_key);
        $creator = $saved->creator;

        if ($creator === null) {
            throw new AccessDeniedHttpException(
                'The person who saved this report no longer has an account. Recreate it under an active user.',
            );
        }

        $this->assertPermitted($report, $creator);

        return [
            'report' => $report,
            'result' => $report->run($saved->parameters()),
        ];
    }

    /**
     * Record that a scheduled report ran, and when it runs next.
     */
    public function markRun(SavedReport $saved, ?string $error = null): SavedReport
    {
        $saved->forceFill([
            'last_run_at' => now(),
            'run_count' => (int) $saved->run_count + 1,
            'last_error' => $error,
            'next_run_at' => $this->nextRunAt($saved),
        ])->save();

        return $saved;
    }

    /**
     * When a saved report should next run.
     *
     * Computed in the schedule's own timezone. A report set to run at 8am for
     * an operator in Lisbon must arrive at 8am in Lisbon, and evaluating the
     * expression in UTC would quietly drift it by an hour twice a year.
     */
    public function nextRunAt(SavedReport $saved): ?\DateTimeInterface
    {
        if (! $saved->isScheduled()) {
            return null;
        }

        $timezone = $saved->schedule_timezone
            ?: ($saved->organization?->timezone ?? config('app.timezone'));

        try {
            return (new CronExpression($saved->schedule_cron))
                ->getNextRunDate(new \DateTime('now', new \DateTimeZone($timezone)));
        } catch (\Throwable $exception) {
            // A malformed expression disables the schedule rather than
            // throwing on every sweep: one bad report must not stop everybody
            // else's from being delivered.
            Log::warning('A saved report has an unusable schedule.', [
                'saved_report_id' => $saved->getKey(),
                'cron' => $saved->schedule_cron,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Whether a cron expression is one this platform will accept.
     *
     * Validated on the way in, so an operator learns immediately rather than
     * discovering at month end that the report they were relying on never ran.
     */
    public function isValidSchedule(string $expression): bool
    {
        return CronExpression::isValidExpression($expression);
    }

    private function assertPermitted(ReportInterface $report, User $user): void
    {
        if ($user->isPlatformAdmin()) {
            return;
        }

        if (! $this->access->allows($user, $report->permission())) {
            throw new AccessDeniedHttpException(sprintf(
                'The "%s" report needs the %s permission.',
                $report->name(),
                $report->permission(),
            ));
        }
    }
}
