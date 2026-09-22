<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organization\Models\Organization;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\OwnerAccounting\Services\OwnerStatementBuilder;
use App\Domain\Owners\Models\Owner;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Builds draft statements for periods that have closed.
 *
 * Drafts only, and never approvals. Approving a statement consumes the revenue
 * and expenses behind it so they can never be billed again, and that is not a
 * decision a cron job should be making at two in the morning — a late expense
 * arriving on the 3rd is completely normal, and a human deciding the month is
 * closed is the point at which it stops being.
 *
 * Rebuilding is safe and expected. A draft consumes nothing, so running this
 * every night simply keeps each owner's current figures up to date until
 * somebody approves them.
 */
class GenerateDueOwnerStatements extends Command
{
    protected $signature = 'owner-statements:generate-due
        {--organization= : Restrict to one organization}
        {--period= : The month to build, as YYYY-MM. Defaults to the one that just ended}';

    protected $description = 'Build draft owner statements for the closed period';

    public function handle(OwnerStatementBuilder $builder, TenantContext $tenancy): int
    {
        [$from, $to] = $this->period();

        $organizations = $tenancy->withoutScope(function (): Collection {
            $query = Organization::query();

            if ($this->option('organization') !== null) {
                $query->whereKey($this->option('organization'));
            }

            return $query->get();
        });

        $built = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($organizations as $organization) {
            $tenancy->runAs($organization, function () use (
                $builder, $from, $to, &$built, &$skipped, &$failed
            ): void {
                Owner::query()
                    ->where('is_active', true)
                    ->chunkById(100, function (Collection $owners) use (
                        $builder, $from, $to, &$built, &$skipped, &$failed
                    ): void {
                        foreach ($owners as $owner) {
                            // An owner whose statement for this period has
                            // already been approved is finished with: rebuilding
                            // would either fail on the frozen figures or, worse,
                            // quietly produce a second draft for a period that
                            // is closed.
                            $settled = OwnerStatement::query()
                                ->where('owner_id', $owner->getKey())
                                ->where('period_start', $from->toDateString())
                                ->where('period_end', $to->toDateString())
                                ->where('status', '!=', OwnerStatement::STATUS_DRAFT)
                                ->exists();

                            if ($settled) {
                                $skipped++;

                                continue;
                            }

                            try {
                                $statement = $builder->build($owner, $from, $to);
                            } catch (\Throwable $exception) {
                                $failed++;

                                $this->components->error(sprintf(
                                    '%s: %s',
                                    $owner->display_name ?? $owner->getKey(),
                                    $exception->getMessage(),
                                ));

                                continue;
                            }

                            // An owner with no nights, no costs and no carried
                            // balance has nothing to be told about. The draft is
                            // left in place rather than deleted — it is the
                            // evidence that the period was looked at.
                            if ((int) $statement->nights_sold === 0
                                && $statement->lines()->count() === 0) {
                                $skipped++;

                                continue;
                            }

                            $built++;
                        }
                    });
            });
        }

        $this->components->info(sprintf(
            'Owner statements for %s to %s: %d built, %d skipped, %d failed.',
            $from->toDateString(),
            $to->toDateString(),
            $built,
            $skipped,
            $failed,
        ));

        return self::SUCCESS;
    }

    /**
     * The period to build.
     *
     * The month that just ended, unless one is named. Statements are monthly
     * because that is what management agreements say, and because an owner
     * reconciling against their bank statement is reconciling against a month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(): array
    {
        $option = $this->option('period');

        $month = $option !== null
            ? CarbonImmutable::createFromFormat('Y-m', $option)->startOfMonth()
            : CarbonImmutable::today()->subMonth()->startOfMonth();

        return [$month->startOfMonth(), $month->endOfMonth()->startOfDay()];
    }
}
