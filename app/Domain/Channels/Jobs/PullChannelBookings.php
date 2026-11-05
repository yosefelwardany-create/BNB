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
 * Read a connection's new bookings now, because the channel said one arrived.
 *
 * So a night sold on Airbnb closes on the booking website within seconds,
 * not at the next five-minute import. One waiting per connection: a burst of
 * bookings is read by one import, which takes them all.
 */
class PullChannelBookings implements ShouldBeUniqueUntilProcessing, ShouldQueue
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

        $outcome = $tenancy->runAs($organization, fn (): array => $puller->pullReservationsNow($account));

        if (($outcome['status'] ?? null) === 'running') {
            // Another import holds the connection, and may already be past
            // its bookings: read them once it is done.
            $this->release(30);
        }
    }
}
