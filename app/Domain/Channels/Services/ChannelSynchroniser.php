<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Availability\Services\AvailabilityEngine;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Models\SyncJob;
use App\Domain\Integrations\Contracts\ChannelAdapterInterface;
use App\Domain\Integrations\DataObjects\ChannelAvailabilityUpdate;
use App\Domain\Integrations\DataObjects\ChannelRateUpdate;
use App\Domain\Integrations\DataObjects\ChannelSyncResult;
use App\Domain\Integrations\Registries\ChannelAdapterRegistry;
use App\Domain\Pricing\Services\PricingEngine;
use App\Domain\Properties\Models\Property;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Pushes our truth out to the channels.
 *
 * The platform is always the source of truth for availability and pricing; a
 * channel is a projection. So this service only ever pushes, and the questions
 * it has to answer well are all about the gap between what we believe and what
 * the channel last heard.
 *
 * **Nothing unchanged is pushed.** Each push records a hash of what it sent,
 * and a push whose payload hashes the same is skipped. Channels rate-limit
 * aggressively, and re-sending an identical calendar every five minutes is how
 * an account gets throttled into being unable to send the one update that
 * mattered.
 *
 * **Every attempt is recorded, including the skipped ones.** A channel that
 * silently rejected a price change looks exactly like one that accepted it,
 * until a guest books at the old rate.
 *
 * **A failure is classified, not just counted.** A rejected price will never
 * succeed on retry and needs a person; a timeout should back off and try again.
 * Treating them alike either gives up on recoverable errors or retries
 * hopeless ones until a human notices.
 */
class ChannelSynchroniser
{
    /**
     * Not sent because the account had no ARI budget left this minute.
     *
     * Deliberately distinguished from a failure everywhere it is handled:
     * nothing was attempted, nothing is wrong, and the mapping stays dirty.
     */
    public const ERROR_RATE_LIMITED = 'rate_limited';

    public function __construct(
        private readonly ChannelAdapterRegistry $adapters,
        private readonly AvailabilityEngine $availability,
        private readonly PricingEngine $pricing,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Push everything that has changed since the last successful sync.
     *
     * Bounded by the per-account ARI budget: when an account has spent its
     * allowance for the current minute the remaining mappings are left dirty
     * and reported as deferred, to be drained by the next scheduled run. See
     * {@see hasAriBudget()} for why the work is deferred rather than slept on.
     *
     * @return array{pushed: int, skipped: int, failed: int, deferred: int}
     */
    public function pushPending(?ChannelAccount $account = null): array
    {
        $pushed = 0;
        $skipped = 0;
        $failed = 0;
        $deferred = 0;

        $query = ChannelListing::query()
            ->with(['account', 'listing.property'])
            ->dirty()
            ->when($account !== null, fn ($q) => $q->where('channel_account_id', $account->getKey()));

        foreach ($query->cursor() as $mapping) {
            if ($mapping->account === null || ! $mapping->account->isConnected()) {
                continue;
            }

            // A mapping that has failed repeatedly is left alone rather than
            // retried forever. After a few consecutive failures the cause is
            // almost never transient, and a queue quietly burning retries is
            // how a listing stays wrong for a week with nobody told.
            if ($mapping->isFailing()) {
                $skipped++;

                continue;
            }

            $results = [];

            if ($mapping->availability_dirty && $mapping->account->sync_availability) {
                $results[] = $this->pushAvailability($mapping);
            }

            if ($mapping->rates_dirty && $mapping->account->sync_rates) {
                $results[] = $this->pushRates($mapping);
            }

            foreach ($results as $result) {
                match (true) {
                    $result === null => $skipped++,
                    $result->successful => $pushed++,
                    // Not a failure: nothing was sent and nothing is wrong.
                    // The mapping is still dirty and the next run will take it.
                    $result->errorCode === self::ERROR_RATE_LIMITED => $deferred++,
                    default => $failed++,
                };
            }
        }

        return [
            'pushed' => $pushed,
            'skipped' => $skipped,
            'failed' => $failed,
            'deferred' => $deferred,
        ];
    }

    /**
     * Send the calendar for a mapping's dirty window.
     *
     * Returns null when nothing had changed, which is a successful outcome and
     * deliberately distinguished from a push that happened.
     */
    public function pushAvailability(ChannelListing $mapping, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, bool $force = false): ?ChannelSyncResult
    {
        if (! $mapping->account?->sync_availability) {
            return ChannelSyncResult::permanentFailure('push_disabled', 'Outbound availability sync is disabled for this connection.');
        }
        $adapter = $this->adapterFor($mapping);

        if (! $adapter->supports(ChannelAdapterInterface::CAPABILITY_AVAILABILITY)) {
            // An iCal feed has no availability push. Skipping is correct;
            // failing would fill the log with errors nobody can act on.
            return null;
        }

        // Checked here, before the calendar is built: a throttled account
        // should cost nothing, not a full run of the availability engine whose
        // output it is not allowed to send.
        if (! $this->hasAriBudget($mapping, 'availability')) {
            return $this->rateLimited('availability');
        }

        [$start, $end] = $this->window($mapping, $from, $to);

        $listing = $mapping->listing;

        if ($listing === null) {
            return $this->recordFailure($mapping, 'availability', 'listing_missing', 'The mapped listing no longer exists.', false);
        }

        $days = [];
        $minimumStay = [];
        $closedToArrival = [];
        $closedToDeparture = [];
        $stopSell = [];

        foreach ($this->availability->calendar($listing, $start, $end) as $day) {
            // Units remaining, not a boolean. A channel selling one of three
            // identical studios needs to know two are left, and collapsing
            // that to "available" is how the other two go unsold.
            $days[$day->date] = $day->isAvailable ? $day->remainingUnits : 0;

            $minimumStay[$day->date] = $day->minimumNights;
            $closedToArrival[$day->date] = $day->closedToArrival;
            $closedToDeparture[$day->date] = $day->closedToDeparture;

            // A night closed by hand, as opposed to one that merely sold out.
            // The engine already distinguishes the two; until now only the
            // zero in `days` above left the building, which says the weaker
            // of the two things.
            $stopSell[$day->date] = $day->isManuallyBlocked;
        }

        $sendsStopSell = $adapter->supports(ChannelAdapterInterface::CAPABILITY_STOP_SELL);

        // The hash stands for what this channel was last sent, so the
        // stop-sell map belongs in it only where it is actually sent.
        // Otherwise adding the field would invalidate every stored hash and
        // trigger one pointless full re-push per mapping on adapters that
        // cannot use it.
        $hash = $this->hash($days + [
            'min' => $minimumStay,
            'cta' => $closedToArrival,
            'ctd' => $closedToDeparture,
        ] + ($sendsStopSell ? ['stop' => $stopSell] : []));

        // Unchanged since the last successful push. Sending it again spends
        // rate limit we will want for the update that matters.
        //
        // A full sync overrides this on purpose: its whole job is to restate
        // what we believe regardless of what we think the channel already
        // knows, which is what makes it a recovery from a dropped message.
        if (! $force && $hash === $mapping->availability_hash) {
            $this->recordSkip($mapping, 'availability', 'Nothing has changed since the last push.');

            $mapping->forceFill(['availability_dirty' => false])->save();

            return null;
        }

        $job = $this->openJob($mapping, 'availability', $start, $end, ['days' => count($days)], $adapter);

        $this->consumeAriSlot($mapping, 'availability');

        $result = $adapter->pushAvailability($mapping, new ChannelAvailabilityUpdate(
            from: $start->toDateTimeImmutable(),
            to: $end->toDateTimeImmutable(),
            days: $days,
            minimumStay: $minimumStay,
            closedToArrival: $closedToArrival,
            closedToDeparture: $closedToDeparture,
            stopSell: $sendsStopSell ? $stopSell : [],
        ));

        $this->closeJob($job, $result, count($days));

        if ($result->successful) {
            $mapping->forceFill([
                'availability_dirty' => false,
                'availability_hash' => $hash,
                'availability_pushed_at' => now(),
                'consecutive_failures' => 0,
                'last_error' => null,
                'status' => ChannelListing::STATUS_PUBLISHED,
            ])->save();
        } else {
            $this->applyFailure($mapping, $result);
        }

        return $result;
    }

    /**
     * Send nightly rates and stay restrictions for a mapping's dirty window.
     */
    public function pushRates(ChannelListing $mapping, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, bool $force = false): ?ChannelSyncResult
    {
        if (! $mapping->account?->sync_rates) {
            return ChannelSyncResult::permanentFailure('push_disabled', 'Outbound rate sync is disabled for this connection.');
        }
        $adapter = $this->adapterFor($mapping);

        if (! $adapter->supports(ChannelAdapterInterface::CAPABILITY_PRICING)) {
            return null;
        }

        // As above: peek before pricing the whole window. Rates and
        // restrictions share one ceiling at the far end, separate from
        // availability's.
        if (! $this->hasAriBudget($mapping, 'rates')) {
            return $this->rateLimited('rates');
        }

        [$start, $end] = $this->window($mapping, $from, $to);

        $listing = $mapping->listing;

        if ($listing === null) {
            return $this->recordFailure($mapping, 'rates', 'listing_missing', 'The mapped listing no longer exists.', false);
        }

        $rates = [];

        foreach ($this->pricing->rateCalendar($listing, $start, $end) as $date => $amount) {
            // The channel's price, after this mapping's markup. The same room
            // is often sold dearer where the guest is charged less visibly.
            $rates[$date] = $mapping->adjustedRate($amount->minorUnits);
        }

        /*
         * Stay restrictions ride with the rates.
         *
         * `ChannelRateUpdate` has carried `minimumStay` and `maximumStay`
         * since it was written, and the Hostex adapter reads both and sends
         * them as `min_stay` and `max_stay` — but nothing here ever filled
         * them, so every rate push has sent two nulls. A maximum stay set on
         * the calendar marked the mapping dirty and then died at this
         * boundary: the flag was set, the push happened, and the restriction
         * stayed local. Exactly the shape of the bug that this file's
         * availability path had.
         *
         * Read from the availability engine rather than straight off
         * `calendar_days`, because a night with no override still has the
         * listing's own minimum, and a channel told nothing would keep selling
         * one-night stays.
         *
         * Nulls are omitted rather than sent: an adapter reads these with
         * `?? null`, so a missing key and a null key mean the same thing, and
         * omitting keeps the payload — and its hash — honest about what was
         * actually set.
         */
        $minimumStay = [];
        $maximumStay = [];
        $closedToArrival = [];
        $closedToDeparture = [];
        $stopSell = [];

        foreach ($this->availability->calendar($listing, $start, $end) as $day) {
            if ($day->minimumNights !== null) {
                $minimumStay[$day->date] = $day->minimumNights;
            }

            if ($day->maximumNights !== null) {
                $maximumStay[$day->date] = $day->maximumNights;
            }

            // The arrival rules and the hand-closed nights travel with the
            // rates as well as with the availability, because a channel that
            // keys restrictions to a rate plan — Channex does — cannot read
            // them off an availability message that only carries counts.
            $closedToArrival[$day->date] = $day->closedToArrival;
            $closedToDeparture[$day->date] = $day->closedToDeparture;
            $stopSell[$day->date] = $day->isManuallyBlocked;
        }

        // The restrictions are part of what this channel was last sent, so
        // they belong in the hash. This does mean every mapping's stored rate
        // hash stops matching once, and re-pushes rates a single time — which
        // is the point: those pushes carry restrictions that never went out.
        $hash = $this->hash($rates + [
            'min' => $minimumStay,
            'max' => $maximumStay,
            'cta' => $closedToArrival,
            'ctd' => $closedToDeparture,
            'stop' => $stopSell,
        ]);

        if (! $force && $hash === $mapping->rates_hash) {
            $this->recordSkip($mapping, 'rates', 'Nothing has changed since the last push.');

            $mapping->forceFill(['rates_dirty' => false])->save();

            return null;
        }

        $job = $this->openJob($mapping, 'rates', $start, $end, ['nights' => count($rates)], $adapter);

        $this->consumeAriSlot($mapping, 'rates');

        $result = $adapter->pushRates($mapping, new ChannelRateUpdate(
            from: $start->toDateTimeImmutable(),
            to: $end->toDateTimeImmutable(),
            currency: $listing->currency,
            nightlyRates: $rates,
            minimumStay: $minimumStay,
            maximumStay: $maximumStay,
            closedToArrival: $closedToArrival,
            closedToDeparture: $closedToDeparture,
            stopSell: $stopSell,
        ));

        $this->closeJob($job, $result, count($rates));

        if ($result->successful) {
            $mapping->forceFill([
                'rates_dirty' => false,
                'rates_hash' => $hash,
                'rates_pushed_at' => now(),
                'consecutive_failures' => 0,
                'last_error' => null,
            ])->save();
        } else {
            $this->applyFailure($mapping, $result);
        }

        return $result;
    }

    /**
     * Restate everything we believe about one mapping.
     *
     * Not a scheduled sweep. A full sync is what a channel needs when a
     * connection first goes live, and what recovers from a message that was
     * dropped, rejected or never sent — so it sends the whole window
     * regardless of the content hash, which is exactly what the ordinary push
     * path refuses to do.
     *
     * Two calls, one for availability and one for rates and restrictions,
     * because that is the shape Channex's certification asks for and the shape
     * every other adapter here already produces.
     *
     * 500 days by default, which is what Channex specifies. Deliberately not
     * the 730-day publishing horizon: a full sync is a defined operation with
     * a defined size, and sending half again as much data to be thorough would
     * make the one message that must fit under 10mb larger for no benefit.
     *
     * The caller decides when. Channex allows a full sync once every 24 hours
     * and rejects any integration that sends one on a short timer instead of
     * sending changes, so nothing in this class schedules it.
     *
     * @return array{availability: ?ChannelSyncResult, rates: ?ChannelSyncResult}
     */
    public function fullSync(ChannelListing $mapping, ?int $days = null): array
    {
        $days = $days ?? (int) config('pms.channels.full_sync_days', 500);

        $from = CarbonImmutable::today();
        $to = $from->addDays(max(1, $days));

        return [
            'availability' => $this->pushAvailability($mapping, $from, $to, force: true),
            'rates' => $this->pushRates($mapping, $from, $to, force: true),
        ];
    }

    /**
     * Mark every mapping of a listing as needing a push.
     *
     * Called whenever availability or pricing changes locally. Deliberately
     * cheap — it sets a flag rather than pushing — so the booking path is
     * never slowed by however many channels a listing is published to, and a
     * channel being down cannot fail a guest's reservation.
     */
    public function markListingDirty(
        string $listingId,
        string $what = 'both',
        ?string $from = null,
        ?string $to = null,
    ): int {
        $mappings = ChannelListing::query()
            ->active()
            ->where('listing_id', $listingId)
            ->get();

        foreach ($mappings as $mapping) {
            $mapping->markDirty($what, $from, $to);
        }

        return $mappings->count();
    }

    /**
     * Mark every mapping within a scope as needing a push.
     *
     * Exists because most pricing is not attached to one listing. A rate plan
     * belongs to a property, a portfolio, or the whole organization; a pricing
     * rule can name any of those or a single listing. {@see markListingDirty()}
     * cannot express that, and resolving it at each call site is how one of
     * them ends up marking nothing.
     *
     * Narrowing is most-specific-wins: a listing if given, else a property,
     * else a portfolio's properties, else every active mapping in the tenant —
     * which is correct for an organization-wide plan, whose scope genuinely is
     * everything.
     *
     * Widening the date window is safe. Each mapping stores a content hash of
     * its last successful push, so an edit that moved no derived price hashes
     * the same and is skipped rather than re-sent. Marking too narrowly has no
     * equivalent safety net: those nights simply stay wrong on the channel.
     *
     * @return int The number of mappings marked.
     */
    public function markScopeDirty(
        string $what = 'both',
        ?string $propertyId = null,
        ?string $portfolioId = null,
        ?string $listingId = null,
        ?string $from = null,
        ?string $to = null,
    ): int {
        $query = ChannelListing::query()->active();

        if ($listingId !== null) {
            $query->where('listing_id', $listingId);
        } elseif ($propertyId !== null) {
            $query->where('property_id', $propertyId);
        } elseif ($portfolioId !== null) {
            $query->whereIn(
                'property_id',
                Property::query()->where('portfolio_id', $portfolioId)->select('id'),
            );
        }

        $mappings = $query->get();

        foreach ($mappings as $mapping) {
            $mapping->markDirty($what, $from, $to);
        }

        return $mappings->count();
    }

    /**
     * Check that a connection's credentials still work.
     */
    public function verify(ChannelAccount $account): ChannelSyncResult
    {
        $adapter = $this->adapters->make($account->channel);

        $job = SyncJob::query()->create([
            'organization_id' => $account->organization_id,
            'channel_account_id' => $account->getKey(),
            'kind' => 'connection_test',
            'direction' => 'push',
            'status' => SyncJob::RUNNING,
            'started_at' => now(),
            'is_simulated' => ! $adapter->isLive(),
        ]);

        $result = $adapter->testConnection($account);

        $this->closeJob($job, $result, 0);

        $account->forceFill([
            'status' => $result->successful
                ? ChannelAccount::STATUS_CONNECTED
                : ChannelAccount::STATUS_ERROR,
            'last_verified_at' => now(),
            'connected_at' => $result->successful ? ($account->connected_at ?? now()) : $account->connected_at,
            'last_error' => $result->errorMessage,
        ])->save();

        return $result;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function adapterFor(ChannelListing $mapping): ChannelAdapterInterface
    {
        return $this->adapters->make($mapping->account?->channel ?? 'direct');
    }

    /**
     * The date range to push.
     *
     * The mapping's dirty window where it has one, otherwise the whole
     * publishing horizon. Pushing only what changed is the difference between
     * a calendar update that takes a second and one that takes a minute and
     * exhausts the rate limit.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(ChannelListing $mapping, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $horizon = (int) config('pms.availability.horizon_days', 730);

        $start = $from ?? $mapping->dirty_from ?? CarbonImmutable::today();
        $end = $to ?? $mapping->dirty_to ?? CarbonImmutable::today()->addDays($horizon);

        // Never push the past: a channel cannot sell yesterday, and sending it
        // is wasted payload that some adapters reject outright.
        if ($start->lessThan(CarbonImmutable::today())) {
            $start = CarbonImmutable::today();
        }

        $limit = CarbonImmutable::today()->addDays($horizon);

        return [$start, $end->greaterThan($limit) ? $limit : $end];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function openJob(
        ChannelListing $mapping,
        string $kind,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $summary,
        ChannelAdapterInterface $adapter,
    ): SyncJob {
        return SyncJob::query()->create([
            'organization_id' => $mapping->organization_id,
            'channel_account_id' => $mapping->channel_account_id,
            'channel_listing_id' => $mapping->getKey(),
            'kind' => $kind,
            'direction' => 'push',
            'status' => SyncJob::RUNNING,
            'range_start' => $start->toDateString(),
            'range_end' => $end->toDateString(),
            'started_at' => now(),
            'payload_summary' => $summary,
            // Carried per job, not derived later: a report saying
            // "synchronisation succeeded" must not conceal that the adapter
            // talks to nothing.
            'is_simulated' => ! $adapter->isLive(),
        ]);
    }

    private function closeJob(SyncJob $job, ChannelSyncResult $result, int $records): void
    {
        $started = $job->started_at ?? now();

        $job->forceFill([
            'status' => $result->successful ? SyncJob::SUCCEEDED : SyncJob::FAILED,
            'finished_at' => now(),
            'duration_ms' => (int) abs(now()->diffInMilliseconds($started)),
            'records_sent' => $result->successful ? $records : 0,
            'records_failed' => $result->successful ? 0 : $records,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            // A rejected price will never succeed on retry and needs a person;
            // a timeout should back off. The adapter classifies; we obey.
            'is_retryable' => $result->retryable,
            'attempts' => $job->attempts + 1,
            'next_attempt_at' => $result->retryable
                ? now()->addSeconds($job->backoffSeconds())
                : null,
            'result' => $result->data === [] ? null : $result->data,
            // The channel's own id for this push. Kept because it is the
            // handle for every later conversation about one that went wrong,
            // and it used to be dropped on the floor here.
            'external_reference' => $result->externalReference,
        ])->save();
    }

    /**
     * Whether this property may still send an update of this kind this minute.
     *
     * A peek, not a claim: called before the expensive part of a push (the
     * availability and pricing engines run over the whole window to build the
     * payload and its hash) so that a throttled property costs nothing instead
     * of computing a calendar it is not allowed to send.
     *
     * Two things about the shape of this, both taken from Channex's published
     * limits rather than guessed:
     *
     * - The ceiling is **per property**, not per connection. One connection
     *   can carry hundreds of properties, each with its own allowance at the
     *   far end, so budgeting per connection would throttle a large account to
     *   a fraction of what it may actually send.
     * - Availability and rates have **separate** ceilings of 10 each, not a
     *   shared pool of 20. A single bucket of 20 would let 20 availability
     *   calls through and breach the real limit, which is the sort of thing
     *   that gets an account throttled into being unable to send the one
     *   update that mattered.
     *
     * Exhaustion defers rather than sleeps. This runs inside a scheduled
     * command every five minutes, so leaving the mapping dirty costs at most
     * one cycle of staleness and keeps the worker free; sleeping inside the
     * drain would hold a slot open doing nothing and still not raise the
     * ceiling. If throughput ever becomes the binding constraint the lever is
     * a more frequent schedule, never a larger burst — the limit is the
     * channel's, not ours.
     *
     * @param  'availability'|'rates'  $kind
     */
    private function hasAriBudget(ChannelListing $mapping, string $kind): bool
    {
        return ! RateLimiter::tooManyAttempts($this->ariKey($mapping, $kind), $this->ariLimit($kind));
    }

    /**
     * Spend one of this property's slots for this kind of update.
     *
     * Called immediately before the adapter call and never earlier, so a push
     * that turns out to be unchanged — and therefore never reaches the
     * channel — does not spend budget the next real update will want.
     *
     * @param  'availability'|'rates'  $kind
     */
    private function consumeAriSlot(ChannelListing $mapping, string $kind): void
    {
        RateLimiter::hit($this->ariKey($mapping, $kind), 60);
    }

    /**
     * Keyed on the connection *and* the property, because the same property
     * reached through two connections is two properties at the far end, each
     * with its own allowance. Falls back to the listing where a mapping
     * carries no property, so a key is never ambiguous.
     */
    private function ariKey(ChannelListing $mapping, string $kind): string
    {
        $scope = $mapping->property_id ?? $mapping->listing_id ?? $mapping->getKey();

        return sprintf('channel-ari:%s:%s:%s', $mapping->channel_account_id, $scope, $kind);
    }

    private function ariLimit(string $kind): int
    {
        $configured = $kind === 'availability'
            ? config('pms.channels.availability_pushes_per_minute', 10)
            : config('pms.channels.rate_pushes_per_minute', 10);

        return max(1, (int) $configured);
    }

    private function rateLimited(string $kind): ChannelSyncResult
    {
        return ChannelSyncResult::transientFailure(
            self::ERROR_RATE_LIMITED,
            sprintf(
                'The %s update was held back: this property has spent its %d %s updates for this minute. It stays queued.',
                $kind,
                $this->ariLimit($kind),
                $kind,
            ),
        );
    }

    private function recordSkip(ChannelListing $mapping, string $kind, string $reason): void
    {
        SyncJob::query()->create([
            'organization_id' => $mapping->organization_id,
            'channel_account_id' => $mapping->channel_account_id,
            'channel_listing_id' => $mapping->getKey(),
            'kind' => $kind,
            'direction' => 'push',
            'status' => SyncJob::SKIPPED,
            'started_at' => now(),
            'finished_at' => now(),
            'error_message' => $reason,
        ]);
    }

    private function recordFailure(
        ChannelListing $mapping,
        string $kind,
        string $code,
        string $message,
        bool $retryable,
    ): ChannelSyncResult {
        $result = $retryable
            ? ChannelSyncResult::transientFailure($code, $message)
            : ChannelSyncResult::permanentFailure($code, $message);

        SyncJob::query()->create([
            'organization_id' => $mapping->organization_id,
            'channel_account_id' => $mapping->channel_account_id,
            'channel_listing_id' => $mapping->getKey(),
            'kind' => $kind,
            'direction' => 'push',
            'status' => SyncJob::FAILED,
            'started_at' => now(),
            'finished_at' => now(),
            'error_code' => $code,
            'error_message' => $message,
            'is_retryable' => $retryable,
            'attempts' => 1,
        ]);

        $this->applyFailure($mapping, $result);

        return $result;
    }

    private function applyFailure(ChannelListing $mapping, ChannelSyncResult $result): void
    {
        $mapping->forceFill([
            'consecutive_failures' => $mapping->consecutive_failures + 1,
            'last_error' => $result->errorMessage,
            'last_error_at' => now(),
            // Only a permanent failure marks the mapping as broken. A timeout
            // is not a reason to tell an operator their listing is in error.
            'status' => $result->retryable ? $mapping->status : ChannelListing::STATUS_ERROR,
        ])->save();
    }

    /**
     * A stable fingerprint of a payload.
     *
     * Sorted before hashing so that a change in iteration order — which
     * happens whenever a query plan changes — does not look like a change in
     * the data and trigger a pointless push.
     *
     * @param  array<string, mixed>  $payload
     */
    private function hash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
