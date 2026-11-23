<?php

declare(strict_types=1);

namespace App\Domain\Channels\Jobs;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Services\ChannelPuller;
use App\Domain\Organization\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Read a connection's guest messages now, because the channel said one was
 * written.
 *
 * So a guest's question is in the inbox within seconds, without reading every
 * conversation every five minutes to find it, which is what Channex asked
 * Habitat to stop doing. One waiting per connection: a burst of messages is
 * read by one import, which takes them all.
 */
class PullChannelMessages implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Tries, counting the waits behind an import already running. */
    public int $tries = 10;

    /** Seconds a waiting import holds its place against a lost worker. */
    public int $uniqueFor = 300;

    public function __construct(public readonly string $accountId)
    {
        $this->onQueue(config('pms.queues.channels', 'channels'));
    }

    public function uniqueId(): string
    {
        return $this->accountId;
    }

    public function handle(TenantContext $tenancy, ChannelPuller $puller): void
    {
        $account = $tenancy->withoutScope(
            fn (): ?ChannelAccount => ChannelAccount::query()->withoutGlobalScope('organization')->find($this->accountId),
        );
        $organization = $account === null ? null : $tenancy->withoutScope(
            fn (): ?Organization => Organization::query()->find($account->organization_id),
        );

        if ($account === null || $organization === null) {
            return;
        }

        $outcome = $tenancy->runAs($organization, fn (): array => $puller->pullMessagesNow($account));

        if (($outcome['status'] ?? null) === 'running') {
            // Another import holds the connection, and may already be past
            // its messages: read them once it is done.
            $this->release(30);
        }
    }
}
