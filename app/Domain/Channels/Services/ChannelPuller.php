<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Integrations\Contracts\ChannelAdapterInterface;
use App\Domain\Integrations\Contracts\ImportsConversations;
use App\Domain\Integrations\Exceptions\HostexRequestException;
use App\Domain\Integrations\Providers\Channels\HostexChannelAdapter;
use App\Domain\Integrations\Registries\ChannelAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Brings a channel's world into the platform.
 *
 * The piece that was missing. Every part of this existed — an adapter that could
 * read listings, an importer that could map them, a reservation importer, a
 * message importer — and nothing called any of them on a schedule. Connecting a
 * channel produced a row in a table and no data, which is the worst possible
 * version of an integration: it looks connected.
 *
 * ## The order is the design
 *
 * Listings first, always. A reservation arrives naming a listing, and the
 * mapping is what says which of ours that is; pulling bookings before the
 * mapping exists produces a log full of "a channel sent a booking for an
 * unmapped listing" and no bookings. Messages last, because a thread attaches
 * to a booking where there is one.
 *
 * ## What it does not do
 *
 * It does not push. Availability and rates go out through
 * {@see ChannelSynchroniser}, which has its own dirty-tracking and its own
 * backoff, and running both from one place would mean a slow pull delaying every
 * price change.
 *
 * It does not stop on the first failure. A channel that cannot serve reviews is
 * not a reason to abandon the bookings it just served — each stage records what
 * happened and the next one runs.
 */
class ChannelPuller
{
    public function __construct(
        private readonly ChannelAdapterRegistry $adapters,
        private readonly ChannelListingImporter $listings,
        private readonly ReservationImporter $reservations,
        private readonly ChannelMessageImporter $messages,
    ) {}

    /**
     * Pull everything this account is configured to receive.
     *
     * @return array<string, mixed>
     */
    public function pull(ChannelAccount $account, bool $full = false, bool $automatic = false): array
    {
        // A durable lease works through Neon's transaction pooler; session-level
        // advisory locks cannot follow a client between pooled backend sessions.
        $key = 'channel-pull:'.$account->organization_id.':'.$account->id;
        $owner = (string) Str::uuid();
        $lease = ['owner' => $owner, 'expiration' => now()->timestamp + 7200];
        $acquired = DB::table('cache_locks')->insertOrIgnore(['key' => $key] + $lease) === 1
            || DB::table('cache_locks')->where('key', $key)->where('expiration', '<=', now()->timestamp)->update($lease) === 1;
        if (! $acquired) {
            return ['status' => 'running', 'message' => 'A pull is already running for this connection. Refresh its last result shortly.'];
        }
        try {
            // Re-read after acquiring the lease: another worker/manual pull may
            // have completed since this process selected the account.
            $account->refresh();
            if ($automatic && ($account->status !== ChannelAccount::STATUS_CONNECTED
                || $account->channel !== 'hostex'
                || ($account->last_pull_attempted_at !== null && $account->last_pull_attempted_at->gt(now()->subMinutes(5))))) {
                return ['status' => 'not_due'];
            }

            return $this->perform($account, $full, function () use ($key, $owner): bool {
                return DB::table('cache_locks')->where('key', $key)->where('owner', $owner)
                    ->where('expiration', '>', now()->timestamp)
                    ->update(['expiration' => now()->timestamp + 7200]) === 1;
            }, $automatic);
        } finally {
            DB::table('cache_locks')->where('key', $key)->where('owner', $owner)->delete();
        }
    }

    private function perform(ChannelAccount $account, bool $full, callable $renewLease, bool $automatic): array
    {
        $adapter = $this->adapters->make($account->channel);

        $startedAt = CarbonImmutable::now();
        $account->forceFill(['last_pull_attempted_at' => $startedAt])->save();

        /*
         * Where to resume from.
         *
         * A full pull asks for everything, which is what a first connection
         * wants: a property connected this morning has months behind it. After
         * that, an incremental pull from the last run with a day of overlap —
         * because a channel's idea of "changed at" and ours are not the same
         * clock, and re-reading a day costs a few requests while missing a
         * booking costs a guest.
         */
        $since = $full || $account->last_synced_at === null
            ? null
            : CarbonImmutable::parse($account->last_synced_at)->subDay();

        $stages = [
            'listings' => fn () => $this->pullListings($account),
            'properties' => fn () => $account->channel === 'hostex'
                ? $this->attempt('properties', fn () => $this->pullProperties($account))
                : ['skipped' => 'No additional property detail requests.'],
            'availability' => fn () => $account->channel === 'hostex'
                ? $this->attempt('availability', fn () => app(HostexAvailabilitySynchronizer::class)->sync($account))
                : ['skipped' => 'No property availability import.'],
            'reservations' => fn () => $this->pullReservations($account, $since),
            'transactions' => fn () => $account->channel === 'hostex'
                ? $this->attempt('transactions', fn () => app(HostexTransactionImporter::class)->sync($account))
                : ['skipped' => 'No source transaction snapshots.'],
            'messages' => fn () => $this->pullMessages($account, $adapter, $since),
        ];
        $outcome = [];
        foreach ($stages as $name => $work) {
            if (! $renewLease()) {
                $outcome['lock'] = ['failed' => 'The pull lease expired. Retry with a smaller date range.'];
                break;
            }
            $account->forceFill(['last_pull_result' => $outcome + [
                'status' => 'running', 'current_stage' => $name, 'at' => $startedAt->toIso8601String(),
                'trigger' => $automatic ? 'automatic' : 'manual',
            ]])->save();
            $outcome[$name] = $work();
            if ($name === 'properties' && ($account->settings['auto_import_properties'] ?? false) === true) {
                $outcome['listings']['unmapped'] = $account->listings()->whereNull('property_id')->whereNull('listing_id')->count();
            }
        }
        if (! $renewLease()) {
            $outcome['lock'] = ['failed' => 'The pull lease expired before completion. Retry with a smaller date range.'];
        }

        // Written only after every stage has run, and set to when the pull
        // *started*: anything that changed while it was running must be picked
        // up next time rather than skipped as already seen.
        $failed = collect($outcome)->contains(fn ($stage) => ! empty($stage['failed']));
        $outcome += ['status' => $failed ? 'partial' : 'completed', 'since' => $since?->toIso8601String(),
            'trigger' => $automatic ? 'automatic' : 'manual',
            'at' => $startedAt->toIso8601String(), 'completed_at' => now()->toIso8601String()];
        $updates = ['last_pull_result' => $outcome];
        if (! $failed) {
            $updates += ['last_synced_at' => $startedAt, 'last_pull_succeeded_at' => now()];
        }
        $account->forceFill($updates)->save();

        return $outcome;
    }

    /**
     * @return array<string, mixed>
     */
    private function pullListings(ChannelAccount $account): array
    {
        return $this->attempt('listings', fn (): array => $this->listings->importFor($account));
    }

    private function pullProperties(ChannelAccount $account): array
    {
        $report = app(HostexPropertySynchronizer::class)->sync($account);
        $report['created'] = 0;
        if (($account->settings['auto_import_properties'] ?? false) !== true) {
            return $report;
        }
        foreach ($account->listings()->whereNull('property_id')->whereNull('listing_id')->get() as $mapping) {
            try {
                // New local drafts only. Never publish a property or guess that
                // a similar name means it is an existing local property.
                app(ChannelListingAdopter::class)->adopt($mapping, activate: false);
                $report['created']++;
            } catch (Throwable) {
                $report['failed']++;
                $report['issues'][] = 'A discovered property could not be created. Check the plan limit and its mapping, then retry.';
            }
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function pullReservations(ChannelAccount $account, ?CarbonImmutable $since): array
    {
        if (! $account->import_reservations) {
            return ['skipped' => 'This connection is not set to import reservations.'];
        }

        return $this->attempt(
            'reservations',
            fn (): array => $this->reservations->importFor($account, $since),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pullMessages(
        ChannelAccount $account,
        ChannelAdapterInterface $adapter,
        ?CarbonImmutable $since,
    ): array {
        if (! $account->sync_messages) {
            return ['skipped' => 'This connection is not set to sync messages.'];
        }

        if (! $adapter instanceof ImportsConversations) {
            // Said rather than silently counted as zero: "this channel cannot
            // hand over its threads" and "there were no messages" look the same
            // in a count and are nothing alike.
            return ['skipped' => sprintf('%s cannot hand over its conversations.', $adapter->displayName())];
        }

        return $this->attempt('messages', function () use ($account, $adapter, $since): array {
            if ($adapter instanceof HostexChannelAdapter) {
                $adapter->readIssues = [];
            }
            $payloads = $adapter->importConversations($account, $since?->toDateTimeImmutable());

            $recorded = 0;

            foreach ($payloads as $payload) {
                $role = mb_strtolower((string) ($payload->attachments['sender_role'] ?? 'guest'));

                $message = $this->messages->record(
                    $account,
                    $payload,
                    fromGuest: ! in_array($role, ['host', 'outbound', 'owner', 'manager'], true),
                );

                if ($message !== null) {
                    $recorded++;
                }
            }

            return ['seen' => count($payloads), 'recorded' => $recorded, 'failed' => count($adapter->readIssues ?? []), 'issues' => $adapter->readIssues ?? []];
        });
    }

    /**
     * Run one stage, keeping its failure rather than letting it end the pull.
     *
     * A channel that cannot serve reviews is not a reason to abandon the
     * bookings it just served.
     *
     * @param  callable(): array<string, mixed>  $work
     * @return array<string, mixed>
     */
    private function attempt(string $stage, callable $work): array
    {
        try {
            return $work();
        } catch (HostexRequestException $e) {
            // Its own words, and whether it is worth trying again — the
            // difference between a rate limit and a revoked token, which is the
            // first thing somebody debugging this needs to know.
            return ['failed' => $e->getMessage(), 'retryable' => $e->retryable];
        } catch (Throwable $e) {
            Log::warning('A channel pull stage failed.', [
                'stage' => $stage,
                'exception_type' => $e::class,
            ]);

            return ['failed' => 'The '.$stage.' stage could not complete. Retry after checking this connection.', 'retryable' => false];
        }
    }
}
