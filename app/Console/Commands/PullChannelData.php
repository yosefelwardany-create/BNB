<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Services\ChannelPuller;
use App\Domain\Organization\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Brings every connected channel's world into the platform.
 *
 * The scheduler used to call `channels:poll`, which did not exist — so nothing
 * was ever pulled on a timer, and a connection produced a row in a table and no
 * data. This is the command that entry should always have named.
 *
 * Runs across every organization, because the schedule has no tenant: each
 * account is pulled inside its own, which is what keeps one company's bookings
 * out of another's.
 */
class PullChannelData extends Command
{
    protected $signature = 'channels:pull
        {--account= : One connection, rather than every connected one}
        {--automatic : Import connected Hostex accounts that are due, without outbound work}
        {--full : Ask for everything rather than resuming from the last run}';

    protected $description = 'Pull listings, reservations and messages from every connected channel.';

    public function handle(TenantContext $tenancy, ChannelPuller $puller): int
    {
        $accounts = $tenancy->withoutScope(fn () => ChannelAccount::query()
            ->withoutGlobalScope('organization')
            ->where('status', ChannelAccount::STATUS_CONNECTED)
            ->when($this->option('automatic'), fn ($query) => $query->where(fn ($q) => $q->where('channel', 'hostex')->orWhere('last_pull_result->status', 'queued')))
            ->when($this->option('account'), fn ($query, $id) => $query->whereKey($id))
            ->get());

        if ($accounts->isEmpty()) {
            $this->info('No connected channels to pull from.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($accounts as $account) {
            $organization = $tenancy->withoutScope(
                fn (): ?Organization => Organization::query()->find($account->organization_id),
            );

            if ($organization === null) {
                continue;
            }

            // Inside its own tenant, always. Every write the pull makes is
            // scoped by the organization bound here, and that is what keeps one
            // company's bookings out of another's.
            $outcome = $tenancy->runAs(
                $organization,
                fn (): array => $puller->pull($account, (bool) $this->option('full'), automatic: (bool) $this->option('automatic')),
            );

            if (in_array($outcome['status'] ?? '', ['not_due', 'running'], true) && $this->option('automatic')) {
                continue;
            }

            $failed = $failed || in_array($outcome['status'] ?? '', ['partial', 'running'], true);
            $this->line(sprintf(
                '<info>%s</info> · %s — %s',
                $organization->name,
                $account->name,
                $this->summarise($outcome),
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function summarise(array $outcome): string
    {
        $parts = [];

        foreach (['listings', 'properties', 'availability', 'reservations', 'transactions', 'messages'] as $stage) {
            $result = $outcome[$stage] ?? [];

            $parts[] = match (true) {
                ! empty($result['failed']) => sprintf('%s FAILED (%s)', $stage, $result['failed']),
                isset($result['skipped']) => sprintf('%s skipped', $stage),
                default => sprintf('%s %s', $stage, json_encode($result)),
            };
        }

        return implode(' · ', $parts);
    }
}
