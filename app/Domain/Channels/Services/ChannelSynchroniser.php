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
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

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
    public function __construct(
        private readonly ChannelAdapterRegistry $adapters,
        private readonly AvailabilityEngine $availability,
        private readonly PricingEngine $pricing,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Push everything that has changed since the last successful sync.
     *
     * @return array{pushed: int, skipped: int, failed: int}
     */
    public function pushPending(?ChannelAccount $account = null): array
    {
        $pushed = 0;
        $skipped = 0;
        $failed = 0;

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
                    default => $failed++,
                };
            }
        }

        return ['pushed' => $pushed, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Send the calendar for a mapping's dirty window.
     *
     * Returns null when nothing had changed, which is a successful outcome and
     * deliberately distinguished from a push that happened.
     */
    public function pushAvailability(ChannelListing $mapping, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): ?ChannelSyncResult
    {
        $adapter = $this->adapterFor($mapping);

        if (! $adapter->supports(ChannelAdapterInterface::CAPABILITY_AVAILABILITY)) {
            // An iCal feed has no availability push. Skipping is correct;
            // failing would fill the log with errors nobody can act on.
            return null;
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

        foreach ($this->availability->calendar($listing, $start, $end) as $day) {
            // Units remaining, not a boolean. A channel selling one of three
            // identical studios needs to know two are left, and collapsing
            // that to "available" is how the other two go unsold.
            $days[$day->date] = $day->isAvailable ? $day->remainingUnits : 0;

            $minimumStay[$day->date] = $day->minimumNights;
            $closedToArrival[$day->date] = $day->closedToArrival;
            $closedToDeparture[$day->date] = $day->closedToDeparture;
        }

        $hash = $this->hash($days + [
            'min' => $minimumStay,
            'cta' => $closedToArrival,
            'ctd' => $closedToDeparture,
        ]);

        // Unchanged since the last successful push. Sending it again spends
        // rate limit we will want for the update that matters.
        if ($hash === $mapping->availability_hash) {
            $this->recordSkip($mapping, 'availability', 'Nothing has changed since the last push.');

            $mapping->forceFill(['availability_dirty' => false])->save();

            return null;
        }

        $job = $this->openJob($mapping, 'availability', $start, $end, ['days' => count($days)], $adapter);

        $result = $adapter->pushAvailability($mapping, new ChannelAvailabilityUpdate(
            from: $start->toDateTimeImmutable(),
            to: $end->toDateTimeImmutable(),
            days: $days,
            minimumStay: $minimumStay,
            closedToArrival: $closedToArrival,
            closedToDeparture: $closedToDeparture,
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
    public function pushRates(ChannelListing $mapping, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): ?ChannelSyncResult
    {
        $adapter = $this->adapterFor($mapping);

        if (! $adapter->supports(ChannelAdapterInterface::CAPABILITY_PRICING)) {
            return null;
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

        $hash = $this->hash($rates);

        if ($hash === $mapping->rates_hash) {
            $this->recordSkip($mapping, 'rates', 'Nothing has changed since the last push.');

            $mapping->forceFill(['rates_dirty' => false])->save();

            return null;
        }

        $job = $this->openJob($mapping, 'rates', $start, $end, ['nights' => count($rates)], $adapter);

        $result = $adapter->pushRates($mapping, new ChannelRateUpdate(
            from: $start->toDateTimeImmutable(),
            to: $end->toDateTimeImmutable(),
            currency: $listing->currency,
            nightlyRates: $rates,
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
        ])->save();
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
