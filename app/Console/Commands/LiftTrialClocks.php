<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Take the trial countdown off companies that already signed up.
 *
 * Registration used to stamp every new company with a 30-day trial, which
 * nothing enforced — an expired trial is reported and never acted on, so those
 * accounts had unlimited access and a banner saying it was about to end. The
 * default changed; this is for the ones created before it did.
 *
 * Deliberately narrow. It moves `trial` to `active` and clears the end date, and
 * touches nothing else: not a suspended account, not a cancelled one, and not a
 * company whose plan somebody chose on purpose. Widening a command that edits
 * every tenant's billing status is not a thing to do casually.
 */
class LiftTrialClocks extends Command
{
    protected $signature = 'organizations:lift-trials
        {--dry-run : List what would change and change nothing}';

    protected $description = 'Move companies off an unenforced trial countdown onto active access';

    public function handle(TenantContext $tenancy): int
    {
        return $tenancy->withoutScope(function (): int {
            $organizations = Organization::query()
                ->where('status', OrganizationStatus::Trial->value)
                ->orderBy('created_at')
                ->get();

            if ($organizations->isEmpty()) {
                $this->components->info('No company is on a trial countdown.');

                return self::SUCCESS;
            }

            $dryRun = (bool) $this->option('dry-run');

            foreach ($organizations as $organization) {
                $this->line(sprintf(
                    ' %s <fg=gray>%s</> %s',
                    $dryRun ? '<fg=yellow>would lift</>' : '<fg=green>lifted</>',
                    $organization->name,
                    $organization->trial_ends_at === null
                        ? '(no end date)'
                        : '(was ending '.$organization->trial_ends_at->toDateString().')',
                ));

                if ($dryRun) {
                    continue;
                }

                // forceFill: status and trial_ends_at are not mass-assignable,
                // because no request may set either.
                $organization->forceFill([
                    'status' => OrganizationStatus::Active->value,
                    'trial_ends_at' => null,
                ])->save();
            }

            $this->newLine();
            $this->components->info(sprintf(
                '%d company(s) %s.',
                $organizations->count(),
                $dryRun ? 'would move to active access' : 'now have active access with no countdown',
            ));

            return self::SUCCESS;
        });
    }
}
