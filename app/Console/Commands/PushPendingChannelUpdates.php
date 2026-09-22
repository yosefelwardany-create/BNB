<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Channels\Services\ChannelSynchroniser;
use App\Domain\Organization\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Drains the channel push queue.
 *
 * The booking path only ever marks a listing as needing a push; this is what
 * actually sends it. Splitting the two means a slow or unreachable channel can
 * never delay a guest's reservation, at the cost of the calendar being a few
 * minutes behind on the OTAs — which is the right trade, because every channel
 * is eventually consistent anyway.
 */
class PushPendingChannelUpdates extends Command
{
    protected $signature = 'channels:push-pending {--organization= : Restrict to one organization}';

    protected $description = 'Send queued availability and rate changes to the channels';

    public function handle(ChannelSynchroniser $sync, TenantContext $tenancy): int
    {
        if (! config('pms.channels.sync_enabled', true)) {
            $this->components->warn('Channel synchronisation is switched off for this deployment.');

            return self::SUCCESS;
        }

        $organizations = $tenancy->withoutScope(function (): Collection {
            $query = Organization::query();

            if ($this->option('organization') !== null) {
                $query->whereKey($this->option('organization'));
            }

            return $query->get();
        });

        $totals = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($organizations as $organization) {
            // Each tenant is processed inside its own context so the
            // synchroniser's queries are scoped by the data layer rather than
            // by this command remembering to filter.
            $result = $tenancy->runAs($organization, fn (): array => $sync->pushPending());

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }
        }

        $this->components->info(sprintf(
            'Channels: %d pushed, %d unchanged, %d failed.',
            $totals['pushed'],
            $totals['skipped'],
            $totals['failed'],
        ));

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
