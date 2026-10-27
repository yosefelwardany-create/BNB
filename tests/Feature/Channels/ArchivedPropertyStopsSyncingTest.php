<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelSynchroniser;
use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Support\RoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * An archived property stops being sent to its channels.
 *
 * Archiving already took its listings off sale here, and left every channel
 * mapping active — so the next push sent the calendar and prices of a property
 * nobody manages any more, and a price of nothing failed on every run. It was
 * found on three properties made from Channex's demo rooms.
 */
class ArchivedPropertyStopsSyncingTest extends TestCase
{
    use RefreshDatabase;

    public function test_archiving_a_property_stops_its_channel_mappings(): void
    {
        $organization = $this->createOrganization(['base_currency' => 'GBP']);
        $admin = $this->createUser($organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        $property = Property::factory()->create(['organization_id' => $organization->getKey(), 'currency' => 'GBP']);
        $listing = Listing::factory()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $property->getKey(),
            'currency' => 'GBP',
        ]);

        $account = ChannelAccount::query()->create([
            'organization_id' => $organization->getKey(),
            'channel' => 'channex',
            'name' => 'Channex',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'credentials' => ['access_token' => 'test-key'],
            'sync_availability' => true,
            'sync_rates' => true,
        ]);

        $mapping = ChannelListing::query()->create([
            'organization_id' => $organization->getKey(),
            'channel_account_id' => $account->getKey(),
            'listing_id' => $listing->getKey(),
            'property_id' => $property->getKey(),
            'external_listing_id' => 'cd9e2fb1-07dc-44a6-b821-8804e83f47ba',
            'is_active' => true,
            'metadata' => ['channex' => ['property_id' => '09a67eab-b052-46b3-b308-c7de8f493da7', 'rate_plan_id' => '89cb2a47-2530-4d67-8679-a96a1d8a905f']],
        ]);
        $mapping->markDirty('both');

        $this->actingAsUser($admin, $organization)
            ->deleteJson("/api/v1/properties/{$property->getKey()}")
            ->assertOk();

        $this->assertFalse($mapping->fresh()->is_active);

        // And nothing goes out for it, however dirty it was.
        Http::preventStrayRequests();
        Http::fake();

        $this->app->make(ChannelSynchroniser::class)->pushPending($account);

        Http::assertNothingSent();
    }
}
