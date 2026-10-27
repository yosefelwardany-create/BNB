<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelSynchroniser;
use App\Domain\Integrations\DataObjects\ChannelSyncResult;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * Restates everything we believe, for mappings named on the command line.
 *
 * Run by a person, not by a timer, and that distinction is the point. Channex
 * rejects an integration that "just sends full sync on a timer basis" and
 * requires changes to be sent as they happen — which the scheduler already
 * does, by draining dirty flags. This is the other thing: what a connection
 * needs the day it goes live, and what recovers from a message that was
 * dropped or rejected.
 *
 * It is also certification test 1, which asks for 500 days of availability,
 * rates and restrictions in two API calls.
 *
 * Nothing here is wired into the schedule. Channex allows a full sync once
 * every 24 hours, off-peak; if that becomes a routine, it belongs in the
 * schedule file where somebody can see the cadence, not hidden in here.
 */
class FullSyncChannels extends Command
{
    protected $signature = 'channels:full-sync
        {--account= : Every active mapping on one connection}
        {--mapping= : A single mapping}
        {--days= : How many days to restate, default 500}
        {--pause=2 : Seconds between properties, so a large estate does not arrive at once}';

    protected $description = 'Send a full availability and rate restatement for named mappings.';

    public function handle(ChannelSynchroniser $sync, TenantContext $tenancy): int
    {
        if ($this->option('account') === null && $this->option('mapping') === null) {
            /*
             * Refused rather than defaulted to everything.
             *
             * A full sync of every connection on a shared deployment is a very
             * large amount of somebody else's rate limit, and "I meant that
             * one account" is the mistake this guard exists for.
             */
            $this->error('Name what to restate: --account= or --mapping=.');

            return self::FAILURE;
        }

        $mappings = $tenancy->withoutScope(fn () => ChannelListing::query()
            ->withoutGlobalScope('organization')
            ->active()
            // The listing's property too: pricing reads it for every night,
            // and with lazy loading switched off a full sync died on its first
            // mapping before this was eager.
            ->with(['account.organization', 'listing.property'])
            ->when($this->option('account'), fn ($q, $id) => $q->where('channel_account_id', $id))
            ->when($this->option('mapping'), fn ($q, $id) => $q->whereKey($id))
            ->get());

        if ($mappings->isEmpty()) {
            $this->error('No active mapping matched.');

            return self::FAILURE;
        }

        $days = $this->option('days') === null ? null : (int) $this->option('days');
        $pause = max(0, (int) $this->option('pause'));
        $failed = 0;

        $usable = $mappings->filter(function (ChannelListing $mapping): bool {
            if ($mapping->account !== null && $mapping->account->isConnected()) {
                return true;
            }

            $this->line(sprintf('  <fg=yellow>skipped</> %s — the connection is not usable', $mapping->getKey()));

            return false;
        });

        /*
         * One group per channel property, restated together.
         *
         * Certification test 1 wants a property's whole restatement as two
         * calls, all room types in one and all rate plans in the other. The
         * synchroniser sends a group as one message per kind where the channel
         * batches; elsewhere every mapping is its own group, as before.
         */
        $groups = $usable->groupBy(fn (ChannelListing $mapping): string => $mapping->organization_id
            .'|'.($sync->batchKey($mapping) ?? $mapping->getKey()))->values();

        foreach ($groups as $index => $group) {
            $group = $group->values();

            // Each tenant's own context, so the engine's queries are scoped by
            // the data layer rather than by this command remembering to.
            $results = $tenancy->runAs(
                $group->first()->account->organization,
                fn (): array => $sync->fullSyncMany($group->all(), $days),
            );

            foreach ($group as $position => $mapping) {
                $failed += $this->report($mapping, $results[$position]);
            }

            // Channex asks for a few seconds between properties when there are
            // many to restate. The per-property ARI budget would not be
            // breached by two calls, but arriving all at once still queues
            // behind itself at their end.
            if ($pause > 0 && $index < $groups->count() - 1) {
                Sleep::for($pause)->seconds();
            }
        }

        $this->newLine();

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array{availability: ?ChannelSyncResult, rates: ?ChannelSyncResult}  $results
     * @return int The number of pushes that failed.
     */
    private function report(ChannelListing $mapping, array $results): int
    {
        $failed = 0;

        foreach ($results as $kind => $result) {
            if ($result === null) {
                // Null means the adapter does not do this at all — an iCal
                // feed has no rates. Not a failure, and not worth a line that
                // looks like one.
                continue;
            }

            if ($result->successful) {
                $this->line(sprintf(
                    '  <fg=green>sent</>    %-12s %s%s',
                    $kind,
                    $mapping->external_name ?? $mapping->getKey(),
                    // The id the channel gave the task, which is what a
                    // certification form asks to be quoted per test.
                    $result->externalReference === null ? '' : '  task '.$result->externalReference,
                ));

                continue;
            }

            $failed++;
            $this->line(sprintf(
                '  <fg=red>failed</>  %-12s %s — %s',
                $kind,
                $mapping->external_name ?? $mapping->getKey(),
                $result->errorMessage ?? 'no reason given',
            ));
        }

        return $failed;
    }
}
