<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organization\Models\Organization;
use App\Domain\Payments\Services\PaymentScheduleService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Collects instalments that have fallen due.
 *
 * Only instalments the guest agreed to have taken automatically are charged;
 * everything else is merely marked overdue for a human to chase. Taking
 * somebody's card details at booking is not the same as agreeing that the
 * platform may charge them whenever it likes.
 *
 * One tenant's failure never stops the run, and one guest's declined card
 * never stops a tenant. A collection sweep that halts at the first problem
 * quietly stops collecting money for everybody.
 */
class ProcessDuePaymentSchedules extends Command
{
    protected $signature = 'payments:process-due-schedules
        {--organization= : Restrict to one organization}
        {--on= : Treat this date as today (YYYY-MM-DD)}';

    protected $description = 'Charge payment instalments that have fallen due';

    public function handle(PaymentScheduleService $schedules, TenantContext $tenancy): int
    {
        $organizations = $tenancy->withoutScope(function (): Collection {
            $query = Organization::query();

            if ($this->option('organization') !== null) {
                $query->whereKey($this->option('organization'));
            }

            return $query->get();
        });

        $totals = ['charged' => 0, 'failed' => 0, 'marked_overdue' => 0];
        $on = $this->option('on');

        foreach ($organizations as $organization) {
            try {
                $result = $tenancy->runAs(
                    $organization,
                    fn (): array => $schedules->processDue($on),
                );
            } catch (\Throwable $exception) {
                // A broken tenant — a missing processor configuration, a
                // corrupt currency — must not cost every other tenant their
                // collection run.
                $this->components->error(sprintf(
                    '%s: %s',
                    $organization->name,
                    $exception->getMessage(),
                ));

                continue;
            }

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }
        }

        $this->components->info(sprintf(
            'Instalments: %d charged, %d failed, %d now overdue.',
            $totals['charged'],
            $totals['failed'],
            $totals['marked_overdue'],
        ));

        // A declined card is an ordinary business outcome, not a failed job:
        // exiting non-zero here would page somebody every night.
        return self::SUCCESS;
    }
}
