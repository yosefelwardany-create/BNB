<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Availability\Models\CalendarBlock;
use App\Domain\Availability\Models\CalendarDay;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Models\SyncJob;
use App\Domain\Channels\Services\ChannelSynchroniser;
use App\Domain\Integrations\DataObjects\ChannelAvailabilityUpdate;
use App\Domain\Integrations\DataObjects\ChannelSyncResult;
use App\Domain\Integrations\Providers\Channels\SimulatedOtaAdapter;
use App\Domain\Integrations\Registries\ChannelAdapterRegistry;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * What a channel push must record, respect and say.
 *
 * Three properties, all of which were missing and all of which an audit
 * against Channex's PMS certification tests surfaced:
 *
 * - **Evidence.** Every adapter returns the channel's own id for a push, and
 *   `closeJob()` dropped it. It is the handle for every later conversation
 *   about a push that went wrong, and certification asks for it directly.
 * - **Restraint.** Nothing bounded outbound ARI. Channels rate-limit it hard,
 *   and exceeding the ceiling risks the account being throttled into being
 *   unable to send the update that mattered.
 * - **Precision.** A night closed by hand and a night that merely sold out
 *   both left as zero units. They are different statements and a channel that
 *   models them separately expects the difference.
 */
class ChannelPushDisciplineTest extends TestCase
{
    use RefreshDatabase;

    private ChannelSynchroniser $sync;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    private ChannelAccount $account;

    private ChannelListing $mapping;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sync = $this->app->make(ChannelSynchroniser::class);

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'cleaning_fee' => 0,
            'max_occupancy' => 4,
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);

        $this->account = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'airbnb',
            'name' => 'Airbnb — main account',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'sync_availability' => true,
            'sync_rates' => true,
        ]);

        $this->mapping = $this->mappingFor($this->listing, 'ext-listing-1');

        $this->admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        // The limiter is backed by the cache and therefore outlives a single
        // test method on some drivers. Cleared so each test starts with a full
        // budget rather than inheriting whatever the last one spent.
        RateLimiter::clear($this->ariKey());
    }

    // ------------------------------------------------------------------
    // Evidence: the channel's own id for the push
    // ------------------------------------------------------------------

    public function test_a_successful_push_records_the_channel_task_id(): void
    {
        $this->mapping->markDirty('availability');

        $result = $this->sync->pushAvailability($this->mapping);

        $this->assertNotNull($result);
        $this->assertTrue($result->successful);
        $this->assertNotNull($result->externalReference, 'The adapter must report an id.');

        $job = SyncJob::query()
            ->where('channel_listing_id', $this->mapping->getKey())
            ->where('kind', 'availability')
            ->where('status', SyncJob::SUCCEEDED)
            ->firstOrFail();

        // Previously this was dropped: the id existed for the length of one
        // method call and was then unrecoverable.
        $this->assertSame($result->externalReference, $job->external_reference);
    }

    public function test_the_api_exposes_the_channel_task_id(): void
    {
        $this->mapping->markDirty('availability');
        $result = $this->sync->pushAvailability($this->mapping);

        $this->assertNotNull($result?->externalReference);

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->getJson('/api/v1/channel-sync/jobs')
            ->assertOk();

        $references = collect($response->json('data'))->pluck('external_reference')->filter();

        $this->assertContains(
            $result->externalReference,
            $references->all(),
            'The id has to be readable, or it cannot be quoted on a support ticket.',
        );
    }

    // ------------------------------------------------------------------
    // Restraint: the per-minute ARI budget
    // ------------------------------------------------------------------

    public function test_an_account_sends_no_more_than_its_budget_in_a_minute(): void
    {
        config(['pms.channels.ari_pushes_per_minute' => 1]);

        $second = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);
        $secondMapping = $this->mappingFor($second, 'ext-listing-2');

        // Availability only, so each mapping wants exactly one ARI call and
        // the arithmetic is unambiguous.
        $this->mapping->markDirty('availability');
        $secondMapping->markDirty('availability');
        $this->account->forceFill(['sync_rates' => false])->save();

        $totals = $this->sync->pushPending();

        $this->assertSame(1, $totals['pushed']);
        $this->assertSame(1, $totals['deferred']);
        $this->assertSame(0, $totals['failed'], 'A held-back push is not a failure.');
    }

    public function test_a_deferred_push_stays_queued_and_is_not_a_failure(): void
    {
        // No budget at all, so nothing can be sent.
        config(['pms.channels.ari_pushes_per_minute' => 1]);
        RateLimiter::hit($this->ariKey(), 60);

        $this->mapping->markDirty('availability', '2026-12-01', '2026-12-10');

        $result = $this->sync->pushAvailability($this->mapping);

        $this->assertNotNull($result);
        $this->assertFalse($result->successful);
        $this->assertSame(ChannelSynchroniser::ERROR_RATE_LIMITED, $result->errorCode);

        $this->mapping->refresh();

        // Still owed, and still owed for the same window: the next scheduled
        // run must pick this up exactly as it stood.
        $this->assertTrue($this->mapping->availability_dirty);
        $this->assertSame('2026-12-01', $this->mapping->dirty_from->toDateString());
        $this->assertSame('2026-12-10', $this->mapping->dirty_to->toDateString());

        // And it must not count against the mapping. Being throttled is not a
        // fault, and letting it accumulate would eventually trip the
        // repeated-failure guard and stop the mapping being retried at all.
        $this->assertSame(0, (int) $this->mapping->consecutive_failures);
        $this->assertSame(
            0,
            SyncJob::query()
                ->where('channel_listing_id', $this->mapping->getKey())
                ->where('status', SyncJob::FAILED)
                ->count(),
            'A deferral must leave no failed job behind to investigate.',
        );
    }

    public function test_an_unchanged_push_does_not_spend_budget(): void
    {
        config(['pms.channels.ari_pushes_per_minute' => 20]);

        // A real push, which should spend exactly one slot.
        $this->mapping->markDirty('availability');
        $this->sync->pushAvailability($this->mapping);

        $this->assertSame(1, RateLimiter::attempts($this->ariKey()));

        // Dirty again with nothing actually changed: the hash matches, so
        // nothing reaches the channel and no budget may be spent. Spending it
        // here would starve the update that does matter.
        $this->mapping->refresh()->markDirty('availability');
        $this->sync->pushAvailability($this->mapping);

        $this->assertSame(
            1,
            RateLimiter::attempts($this->ariKey()),
            'An unchanged push never reaches the channel, so it must not spend its rate limit.',
        );
    }

    // ------------------------------------------------------------------
    // Precision: closed by hand vs. sold out
    // ------------------------------------------------------------------

    public function test_a_night_closed_by_hand_is_sent_as_a_stop_sell(): void
    {
        $adapter = $this->captureAdapter();
        $date = CarbonImmutable::today()->addDays(5);

        CalendarDay::query()->create([
            'organization_id' => $this->organization->getKey(),
            'listing_id' => $this->listing->getKey(),
            'calendar_date' => $date->toDateString(),
            'is_blocked' => true,
        ]);

        $this->mapping->markDirty('availability', $date->toDateString(), $date->addDays(2)->toDateString());
        $this->sync->pushAvailability($this->mapping);

        $this->assertNotNull($adapter->lastUpdate);
        $this->assertTrue(
            $adapter->lastUpdate->stopSell[$date->toDateString()] ?? false,
            'A night closed by hand must travel as a stop-sell, not only as zero units.',
        );
        $this->assertSame(0, $adapter->lastUpdate->days[$date->toDateString()] ?? null);
    }

    public function test_a_night_lost_to_a_block_is_closed_but_not_a_stop_sell(): void
    {
        $adapter = $this->captureAdapter();
        $date = CarbonImmutable::today()->addDays(5);

        // An owner stay consumes the inventory without being a decision to
        // stop selling. The night is closed either way; only the reason
        // differs, and the two must not be conflated.
        CalendarBlock::query()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'kind' => CalendarBlock::KIND_OWNER_STAY,
            'start_date' => $date->toDateString(),
            'end_date' => $date->addDay()->toDateString(),
        ]);

        $this->mapping->markDirty('availability', $date->toDateString(), $date->addDays(2)->toDateString());
        $this->sync->pushAvailability($this->mapping);

        $this->assertNotNull($adapter->lastUpdate);
        $this->assertSame(0, $adapter->lastUpdate->days[$date->toDateString()] ?? null, 'The night is closed.');
        $this->assertFalse(
            $adapter->lastUpdate->stopSell[$date->toDateString()] ?? true,
            'Selling out is not a stop-sell.',
        );
    }

    public function test_an_adapter_without_the_capability_is_sent_no_stop_sell(): void
    {
        $adapter = $this->captureAdapter(withStopSell: false);
        $date = CarbonImmutable::today()->addDays(5);

        CalendarDay::query()->create([
            'organization_id' => $this->organization->getKey(),
            'listing_id' => $this->listing->getKey(),
            'calendar_date' => $date->toDateString(),
            'is_blocked' => true,
        ]);

        $this->mapping->markDirty('availability', $date->toDateString(), $date->addDays(2)->toDateString());
        $this->sync->pushAvailability($this->mapping);

        $this->assertNotNull($adapter->lastUpdate);
        $this->assertSame(
            [],
            $adapter->lastUpdate->stopSell,
            'Withheld rather than sent and ignored, so the payload never implies a distinction the channel cannot carry.',
        );
        // The night is still closed, by the only means this channel has.
        $this->assertSame(0, $adapter->lastUpdate->days[$date->toDateString()] ?? null);
    }

    // ------------------------------------------------------------------

    private function mappingFor(Listing $listing, string $externalId): ChannelListing
    {
        return ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $this->account->getKey(),
            'listing_id' => $listing->getKey(),
            'property_id' => $this->property->getKey(),
            'external_listing_id' => $externalId,
        ]);
    }

    private function ariKey(): string
    {
        return 'channel-ari:'.$this->account->getKey();
    }

    /**
     * Swap in an adapter that keeps the payload it was handed.
     *
     * The assertions here are about what leaves the building, and the live
     * simulator reports only a summary. Registered on the real registry, which
     * is a singleton, so the synchroniser resolved in setUp() picks this up.
     */
    private function captureAdapter(bool $withStopSell = true): object
    {
        $adapter = new class('airbnb', 'Airbnb', $withStopSell) extends SimulatedOtaAdapter
        {
            public ?ChannelAvailabilityUpdate $lastUpdate = null;

            public function __construct(
                string $key,
                string $name,
                private readonly bool $withStopSell,
            ) {
                parent::__construct($key, $name);
            }

            public function capabilities(): array
            {
                $capabilities = parent::capabilities();

                if ($this->withStopSell) {
                    return $capabilities;
                }

                return array_values(array_diff($capabilities, [self::CAPABILITY_STOP_SELL]));
            }

            public function pushAvailability(ChannelListing $listing, ChannelAvailabilityUpdate $update): ChannelSyncResult
            {
                $this->lastUpdate = $update;

                return parent::pushAvailability($listing, $update);
            }
        };

        $this->app->make(ChannelAdapterRegistry::class)->register('airbnb', fn () => $adapter);

        return $adapter;
    }
}
