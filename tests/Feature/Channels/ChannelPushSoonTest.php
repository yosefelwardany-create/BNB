<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Jobs\PushChannelChanges;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelSynchroniser;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * A change reaches the channel within seconds, not at the next five-minute run.
 *
 * Channex's certification review has somebody change a price in the product
 * while they watch for the call. Marking a mapping dirty stays a flag; what is
 * new is that it queues one push for the connection a few seconds out, so the
 * changes made together still leave together.
 */
class ChannelPushSoonTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private ChannelAccount $account;

    private ChannelListing $mapping;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'USD']);

        $property = Property::factory()->active()->create(['organization_id' => $this->organization->getKey()]);
        $listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
        ]);

        $this->account = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'channex',
            'name' => 'Channex',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'credentials' => ['access_token' => 'test-key'],
            'sync_availability' => true,
            'sync_rates' => true,
        ]);

        $this->mapping = ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $this->account->getKey(),
            'listing_id' => $listing->getKey(),
            'property_id' => $property->getKey(),
            'external_listing_id' => 'cd9e2fb1-07dc-44a6-b821-8804e83f47ba',
            'is_active' => true,
        ]);

        // A real queue, as in production. The suite's own is synchronous.
        config(['queue.default' => 'redis', 'pms.channels.push_delay_seconds' => 10]);
        Queue::fake();
    }

    public function test_changes_made_together_queue_one_push_a_few_seconds_out(): void
    {
        $this->mapping->markDirty('rates', '2026-11-22', '2026-11-22');
        $this->mapping->markDirty('availability', '2026-11-25', '2026-11-25');

        Queue::assertPushedTimes(PushChannelChanges::class, 1);
        Queue::assertPushed(PushChannelChanges::class, function (PushChannelChanges $job): bool {
            $seconds = now()->diffInSeconds($job->delay);

            return $job->accountId === $this->account->getKey() && $seconds > 5 && $seconds <= 10;
        });
    }

    public function test_a_synchronous_queue_leaves_it_to_the_scheduler(): void
    {
        // Pushing inline would put a call to Channex inside the request that
        // saved the price, which marking dirty exists to avoid.
        config(['queue.default' => 'sync']);

        $this->mapping->markDirty('rates');

        Queue::assertNothingPushed();
        $this->assertTrue($this->mapping->fresh()->rates_dirty);
    }

    public function test_the_push_sends_the_connections_pending_changes(): void
    {
        $sync = Mockery::mock(ChannelSynchroniser::class);
        $sync->shouldReceive('pushPending')
            ->once()
            ->withArgs(fn (ChannelAccount $account): bool => $account->is($this->account))
            ->andReturn(['pushed' => 2, 'skipped' => 0, 'failed' => 0, 'deferred' => 0]);
        $this->app->instance(ChannelSynchroniser::class, $sync);

        $this->app->call([new PushChannelChanges($this->account->getKey()), 'handle']);

        Queue::assertNothingPushed();
    }

    public function test_a_push_held_back_by_the_rate_limit_tries_again_in_a_minute(): void
    {
        $sync = Mockery::mock(ChannelSynchroniser::class);
        $sync->shouldReceive('pushPending')->andReturn(['pushed' => 0, 'skipped' => 0, 'failed' => 0, 'deferred' => 1]);
        $this->app->instance(ChannelSynchroniser::class, $sync);

        $this->app->call([new PushChannelChanges($this->account->getKey()), 'handle']);

        Queue::assertPushed(PushChannelChanges::class, fn (PushChannelChanges $job): bool => now()->diffInSeconds($job->delay) > 50);
    }

    public function test_nothing_is_sent_for_a_connection_that_does_not_push(): void
    {
        $this->account->forceFill(['sync_availability' => false, 'sync_rates' => false])->save();

        $sync = Mockery::mock(ChannelSynchroniser::class);
        $sync->shouldNotReceive('pushPending');
        $this->app->instance(ChannelSynchroniser::class, $sync);

        $this->app->call([new PushChannelChanges($this->account->getKey()), 'handle']);

        $this->addToAssertionCount(1);
    }
}
