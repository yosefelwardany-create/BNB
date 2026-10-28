<?php

declare(strict_types=1);

namespace App\Domain\Channels\Jobs;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelSynchroniser;
use App\Domain\Organization\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Sleep;

/**
 * Restate everything a connection's linked listings hold: 500 days of
 * availability, prices and restrictions.
 *
 * What the Full sync button on a connection asks for — the day a property goes
 * live on a channel, and to recover from a message that was dropped. Never on
 * a timer: Channex refuses integrations that send full syncs on a schedule,
 * and requires changes to be sent as they happen, which
 * {@see PushChannelChanges} does.
 *
 * Every room type of a Channex property is restated together, as one
 * availability message and one price-and-restrictions message — certification
 * test 1. Each push is on the sync log with the task id Channex gave it.
 */
class FullSyncChannelAccount implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** A second press while one is running is the same request. */
    public int $uniqueFor = 600;

    public int $timeout = 300;

    public function __construct(public readonly string $accountId)
    {
        $this->onQueue(config('pms.queues.channels', 'channels'));
    }

    public function uniqueId(): string
    {
        return $this->accountId;
    }

    public function handle(ChannelSynchroniser $sync, TenantContext $tenancy): void
    {
        $account = $tenancy->withoutScope(
            fn (): ?ChannelAccount => ChannelAccount::query()->withoutGlobalScope('organization')->find($this->accountId),
        );
        $organization = $account === null ? null : $tenancy->withoutScope(
            fn (): ?Organization => Organization::query()->find($account->organization_id),
        );

        if ($account === null || $organization === null || ! $account->isConnected()) {
            return;
        }

        $tenancy->runAs($organization, function () use ($account, $sync): void {
            $groups = $account->listings()
                ->active()
                // The listing's property too: pricing reads it for every night.
                ->with(['account.organization', 'listing.property'])
                ->get()
                ->groupBy(fn (ChannelListing $mapping): string => $sync->batchKey($mapping) ?? $mapping->getKey())
                ->values();

            foreach ($groups as $index => $group) {
                $sync->fullSyncMany($group->values()->all());

                // Channex asks for a few seconds between properties when
                // there are many to restate.
                if ($index < $groups->count() - 1) {
                    Sleep::for(2)->seconds();
                }
            }
        });
    }
}
