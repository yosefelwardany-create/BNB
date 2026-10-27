<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Agents\Services\AutomaticGuestReplies;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Support\ImportCadence;
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
    /**
     * The live channels the one-minute background import looks at.
     *
     * Everything else is pulled hourly by the scheduler or when somebody asks.
     * Channex is here because bookings are what a channel manager sends, and
     * it re-offers one we have not acknowledged for thirty minutes and then
     * emails a warning: an hourly pull would set that off, and for that hour
     * the night it sold would still be for sale here.
     *
     * @var list<string>
     */
    public const AUTOMATIC_CHANNELS = ['hostex', 'channex'];

    /**
     * The channels whose newly discovered listings can become properties here
     * on import, when the connection is set to.
     *
     * @var list<string>
     */
    public const PROPERTY_CREATING_CHANNELS = ['hostex', 'channex'];

    /**
     * Whether this connection turns what it discovers into properties.
     *
     * Hostex connections store the answer when they are made, and one with
     * none stored does not. Channex connections made before Channex had the
     * setting carry no answer either, and for them creating is the default,
     * because it is what was asked for: a pull that leaves a list of room
     * types and a button to press on each was the complaint.
     */
    public static function createsProperties(ChannelAccount $account): bool
    {
        if (! in_array($account->channel, self::PROPERTY_CREATING_CHANNELS, true)) {
            return false;
        }

        return ($account->settings['auto_import_properties'] ?? $account->channel === 'channex') === true;
    }

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
        return $this->withLease($account, function (callable $renewLease) use ($account, $full, $automatic): array {
            $account->refresh();
            $requested = ($account->last_pull_result['status'] ?? null) === 'queued';
            if ($automatic && ($account->status !== ChannelAccount::STATUS_CONNECTED
                || (! $requested && (! in_array($account->channel, self::AUTOMATIC_CHANNELS, true)
                    || ($account->last_pull_attempted_at !== null
                        // The floor under the one-minute watcher: an account is
                        // looked at no more often than its quickest stage needs,
                        // and {@see ImportCadence} decides the rest.
                        && $account->last_pull_attempted_at->gt(now()->subMinutes(ImportCadence::quickestIntervalMinutes()))))))) {
                return ['status' => 'not_due'];
            }
            if ($requested) {
                $full = $full || ($account->last_pull_result['full'] ?? false);
            }

            return $this->perform($account, $full, $renewLease, $automatic && ! $requested);
        });
    }

    /** Persist a manual request for the import worker without holding HTTP open. */
    public function requestPull(ChannelAccount $account, bool $full = false): array
    {
        return $this->withLease($account, function () use ($account, $full): array {
            $account->refresh();
            abort_unless($account->isConnected(), 422, 'Connect this channel before importing.');
            $queued = ($account->last_pull_result['status'] ?? null) === 'queued';
            $outcome = [
                'status' => 'queued', 'trigger' => 'manual',
                'at' => $queued ? $account->last_pull_result['at'] : now()->toIso8601String(),
                'full' => $full || ($queued && ($account->last_pull_result['full'] ?? false)),
            ];
            $account->forceFill(['last_pull_result' => $outcome])->save();

            return $outcome;
        });
    }

    /**
     * How long a lease survives without being renewed.
     *
     * It was two hours, and the lease is released in a `finally` — which does
     * not run when the process is killed rather than unwound. A deploy, a
     * restart or an out-of-memory in the middle of a pull therefore left the
     * row behind, and every later attempt was turned away with "a pull is
     * already running" for the rest of those two hours. Found on a live
     * connection stuck on `reservations` for fifty minutes, with a guest's
     * message waiting behind it the whole time.
     *
     * Fifteen minutes because the lease is renewed before every stage, so it
     * only ever has to outlive one of them — and the slowest, property details
     * for six listings of seventy-odd photographs apiece, is minutes rather
     * than hours. A crash now costs a quarter of an hour instead of an
     * afternoon.
     */
    private const LEASE_SECONDS = 900;

    private function withLease(ChannelAccount $account, callable $work): array
    {
        // A durable lease works through Neon's transaction pooler; session-level
        // advisory locks cannot follow a client between pooled backend sessions.
        $key = 'channel-pull:'.$account->organization_id.':'.$account->id;
        $owner = (string) Str::uuid();
        $lease = ['owner' => $owner, 'expiration' => now()->timestamp + self::LEASE_SECONDS];

        if (DB::table('cache_locks')->insertOrIgnore(['key' => $key] + $lease) !== 1) {
            // Nothing acquired cleanly, so either a pull really is running or
            // the last one died holding the key. Taking over an expired lease
            // is the second case, and it is worth knowing which happened: the
            // connection screen has been saying "running" ever since, about a
            // process that is not there.
            if (DB::table('cache_locks')->where('key', $key)->where('expiration', '<=', now()->timestamp)->update($lease) !== 1) {
                return ['status' => 'running', 'message' => 'A pull is already running for this connection. Refresh its last result shortly.'];
            }

            $this->noteInterruptedPull($account);
        }

        try {
            return $work(function () use ($key, $owner): bool {
                return DB::table('cache_locks')->where('key', $key)->where('owner', $owner)
                    ->where('expiration', '>', now()->timestamp)
                    ->update(['expiration' => now()->timestamp + self::LEASE_SECONDS]) === 1;
            });
        } finally {
            DB::table('cache_locks')->where('key', $key)->where('owner', $owner)->delete();
        }
    }

    /**
     * Close off a pull that died holding the lease.
     *
     * Without this the stage it was on reads "running…" on the connection
     * screen indefinitely — long after the process that was running it stopped
     * existing — and somebody watching an inbox has no way to tell a slow
     * import from a dead one. The stage stamps are left exactly as they were,
     * so whatever did finish is not re-fetched.
     */
    private function noteInterruptedPull(ChannelAccount $account): void
    {
        $account->refresh();
        $previous = $account->last_pull_result ?? [];

        if (($previous['status'] ?? null) !== 'running') {
            return;
        }

        $stage = $previous['current_stage'] ?? null;

        /*
         * Spread, not `+`. The union operator keeps the left-hand value for a
         * duplicate key, so `$previous + ['status' => 'interrupted']` leaves
         * the status reading "running" — the exact thing this exists to stop.
         */
        $account->forceFill(['last_pull_result' => [
            ...$previous,
            'status' => 'interrupted',
            'message' => $stage === null
                ? 'The previous import stopped before it finished. This run starts again from where it left off.'
                : sprintf('The previous import stopped during %s, most likely because the worker restarted. This run starts again from where it left off.', $stage),
            'interrupted_at' => now()->toIso8601String(),
        ]])->save();
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
            'properties' => fn () => match (true) {
                $account->channel === 'hostex' => $this->attempt('properties', fn () => $this->pullProperties($account)),
                // Channex has no details to fetch beyond what the listings
                // stage read; what is left is making the properties.
                self::createsProperties($account) => $this->attempt(
                    'properties',
                    fn () => $this->createDiscoveredProperties($account, ['created' => 0, 'failed' => 0, 'issues' => []]),
                ),
                default => ['skipped' => 'No additional property detail requests.'],
            },
            'availability' => fn () => match ($account->channel) {
                'hostex' => $this->attempt('availability', fn () => app(HostexAvailabilitySynchronizer::class)->sync($account)),
                // Once per room type, to start from Channex's prices; after
                // that Habitat's calendar is the one that is sent.
                'channex' => $this->attempt('availability', fn () => app(ChannexCalendarSeeder::class)->seed($account)),
                default => ['skipped' => 'No property availability import.'],
            },
            'reservations' => fn () => $this->pullReservations($account, $since),
            'transactions' => fn () => $account->channel === 'hostex'
                ? $this->attempt('transactions', fn () => app(HostexTransactionImporter::class)->sync($account))
                : ['skipped' => 'No source transaction snapshots.'],
            'messages' => fn () => $this->pullMessages($account, $adapter, $since),
        ];
        // An automatic run only does the stages that are due (see
        // ImportCadence); a pull somebody asked for does all of them. Each
        // stage that completes is stamped, so the next automatic run knows
        // when it last ran.
        $previous = $account->last_pull_result['stages_at'] ?? [];
        $stagesAt = is_array($previous) ? $previous : [];
        $due = $automatic ? ImportCadence::dueStages($stagesAt, $startedAt) : array_keys($stages);

        $outcome = [];
        foreach ($stages as $name => $work) {
            if (! $renewLease()) {
                $outcome['lock'] = ['failed' => 'The pull lease expired. Retry with a smaller date range.'];
                break;
            }
            if (! in_array($name, $due, true)) {
                $outcome[$name] = ['skipped' => ImportCadence::skippedNote($name)];

                continue;
            }
            $account->forceFill(['last_pull_result' => $outcome + [
                'status' => 'running', 'current_stage' => $name, 'at' => $startedAt->toIso8601String(),
                'trigger' => $automatic ? 'automatic' : 'manual', 'stages_at' => $stagesAt,
            ]])->save();
            $outcome[$name] = $work();
            // Stamped unless the stage failed as a whole (a message). A count of
            // items that failed inside it (one photo, one calendar day) is not
            // a reason to repeat the whole stage every five minutes.
            if (! is_string($outcome[$name]['failed'] ?? null)) {
                $stagesAt[$name] = $startedAt->toIso8601String();
            }
            // A newly discovered listing gets its details now, not in six hours.
            if ($name === 'listings' && (int) ($outcome['listings']['created'] ?? 0) > 0 && ! in_array('properties', $due, true)) {
                $due[] = 'properties';
            }
            if ($name === 'properties' && self::createsProperties($account)) {
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
            'trigger' => $automatic ? 'automatic' : 'manual', 'stages_at' => $stagesAt,
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
        if (! self::createsProperties($account)) {
            return $report;
        }

        return $this->createDiscoveredProperties($account, $report);
    }

    /**
     * A property here for each discovered listing nothing is linked to yet.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function createDiscoveredProperties(ChannelAccount $account, array $report): array
    {
        $report['created'] ??= 0;
        $report['failed'] ??= 0;
        $samples = 0;

        foreach ($account->listings()->whereNull('property_id')->whereNull('listing_id')->get() as $mapping) {
            // Channex's own sample property, which every staging account comes
            // with. Listed, so it can still be linked by hand, but never made
            // into properties nobody asked for.
            if (($mapping->metadata[$account->channel]['is_demo'] ?? false) === true) {
                $samples++;

                continue;
            }

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

        if ($samples > 0) {
            $report['issues'][] = sprintf(
                '%d room type%s in Channex\'s demo property %s left out.',
                $samples,
                $samples === 1 ? '' : 's',
                $samples === 1 ? 'was' : 'were',
            );
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
            // Cleared per run, so the tally below describes this import and not
            // every import since the worker started.
            $this->messages->declined = [];
            $payloads = $adapter->importConversations($account, $since?->toDateTimeImmutable());

            $recorded = 0;
            $replyCandidates = [];

            foreach ($payloads as $payload) {
                $role = mb_strtolower((string) ($payload->attachments['sender_role'] ?? 'guest'));

                $message = $this->messages->record(
                    $account,
                    $payload,
                    fromGuest: ! in_array($role, ['host', 'outbound', 'owner', 'manager'], true),
                    // Importing history is not a new guest event. Do not fire
                    // reply automations or downstream webhooks for these rows.
                    dispatchEvent: false,
                    allowAutomaticReply: false,
                );

                if ($message !== null) {
                    $recorded++;
                    if ($payload->sentAt !== null) {
                        $replyCandidates[] = $message;
                    }
                }
            }

            $this->messages->refreshImportedThreads($account, $payloads);
            // Evaluate only after both sides of every imported thread are present.
            foreach ($replyCandidates as $message) {
                app(AutomaticGuestReplies::class)->schedule($message);
            }

            /*
             * `seen` minus `recorded` used to be a silence.
             *
             * An import reported eight seen and one recorded, and answering
             * "where did the other seven go" meant reading the importer and
             * guessing which of its four exits they took. Seven already known
             * is an incremental pull working as designed; seven dropped for an
             * unmapped listing is a guest talking to a property this platform
             * cannot name. The difference decides whether anybody needs to act.
             */
            return ['seen' => count($payloads), 'recorded' => $recorded,
                'declined' => $this->messages->declined,
                'unmapped_threads' => $adapter instanceof HostexChannelAdapter ? $adapter->unmappedConversationCount : 0,
                'failed' => count($adapter->readIssues ?? []), 'issues' => $adapter->readIssues ?? []];
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
