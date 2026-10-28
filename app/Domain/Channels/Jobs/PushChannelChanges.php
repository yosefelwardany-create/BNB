<?php

declare(strict_types=1);

namespace App\Domain\Channels\Jobs;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Services\ChannelSynchroniser;
use App\Domain\Organization\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Send a connection's pending changes a few seconds after they were made.
 *
 * Marking a mapping dirty stays cheap — a flag, never a call to the channel —
 * so saving a price or taking a booking is never held up by however many
 * channels a listing is on. This is what then sends it: queued once per
 * connection, a few seconds out, so the changes somebody makes in the same
 * moment travel together as one message per property, which is what Channex's
 * certification asks for. A change no longer waits for the five-minute
 * scheduler, which stays as the net under anything this misses.
 *
 * Unique per connection until it starts: every change in the window lands in
 * the one push already waiting, and a change made while it is running queues
 * the next one.
 */
class PushChannelChanges implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Seconds a waiting push holds its place against a lost worker. */
    public int $uniqueFor = 300;

    public function __construct(public readonly string $accountId)
    {
        $this->onQueue(config('pms.queues.channels', 'channels'));
    }

    /**
     * Queue a push for a connection, unless one is already waiting.
     *
     * Never fails the change that asked for it: the mapping is already marked,
     * and the scheduler sends it if this cannot be queued. Nothing is queued
     * on a synchronous queue, which cannot wait, and would put a call to the
     * channel inside the request that made the change.
     */
    public static function soon(?string $accountId): void
    {
        $delay = config('pms.channels.push_delay_seconds');

        if ($accountId === null || $delay === null || config('queue.default') === 'sync'
            || ! config('pms.channels.sync_enabled', true)) {
            return;
        }

        try {
            self::dispatch($accountId)->delay(now()->addSeconds((int) $delay))->afterCommit();
        } catch (Throwable $e) {
            Log::warning('Could not queue a channel push; the scheduler will send it.', [
                'channel_account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
        }
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

        if ($account === null || ! $account->isConnected() || (! $account->sync_availability && ! $account->sync_rates)) {
            return;
        }

        $organization = $tenancy->withoutScope(fn (): ?Organization => Organization::query()->find($account->organization_id));

        if ($organization === null) {
            return;
        }

        $result = $tenancy->runAs($organization, fn (): array => $sync->pushPending($account));

        // Held back by the channel's per-minute allowance: try again once it
        // has refilled, rather than leaving it to the scheduler.
        if (($result['deferred'] ?? 0) > 0) {
            self::dispatch($this->accountId)->delay(now()->addMinute());
        }
    }
}
