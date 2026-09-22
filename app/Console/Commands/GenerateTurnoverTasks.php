<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Operations\Services\TurnoverScheduler;
use App\Domain\Organization\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Creates the cleaning work implied by upcoming departures.
 *
 * Confirming a booking already schedules its turnover through the event
 * listener; this is the safety net for everything that path can miss — a
 * booking imported from a channel, a reservation created before the listener
 * existed, a queue that lost a job. Generation is idempotent, so running it
 * changes nothing where the listener already did its work.
 *
 * The window is short and forward-looking on purpose. Creating cleans months
 * ahead would mean regenerating them every time a booking moved, and a rota
 * full of speculative work is worse than no rota.
 */
class GenerateTurnoverTasks extends Command
{
    protected $signature = 'operations:generate-turnover-tasks
        {--days=14 : How far ahead to generate}
        {--organization= : Restrict to one organization}';

    protected $description = 'Create cleaning tasks for departures in the coming days';

    public function handle(TurnoverScheduler $scheduler, TenantContext $tenancy): int
    {
        $from = CarbonImmutable::today();
        $to = $from->addDays(max(1, (int) $this->option('days')));

        $organizations = $tenancy->withoutScope(function (): \Illuminate\Support\Collection {
            $query = Organization::query();

            if ($this->option('organization') !== null) {
                $query->whereKey($this->option('organization'));
            }

            return $query->get();
        });

        $created = 0;
        $skipped = 0;

        foreach ($organizations as $organization) {
            // Each organization is processed inside its own tenant so the
            // scheduler's queries are scoped by the data layer rather than by
            // this command remembering to filter.
            $result = $tenancy->runAs(
                $organization,
                fn (): array => $scheduler->generateForWindow($from, $to),
            );

            $created += $result['created'];
            $skipped += $result['skipped'];
        }

        $this->components->info(sprintf(
            'Turnovers to %s: %d created, %d already existed.',
            $to->toDateString(),
            $created,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
