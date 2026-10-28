<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelListingImporter;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Support\RoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bookings from Channex arrive within minutes, not within the hour.
 *
 * The one-minute background import only ever looked at Hostex connections, so
 * a Channex booking waited for the hourly pull. Channex re-offers a revision it
 * has not been told we have for thirty minutes and then emails a warning, and
 * an hour is also an hour in which the night it sold is still for sale here.
 */
class ChannexAutomaticImportTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY = '716305c4-561a-4561-a187-7f5b8aeb5920';

    private const ROOM_TYPE = '994d1375-dbbd-4072-8724-b2ab32ce781b';

    private Organization $organization;

    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'USD']);

        $this->account = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'channex',
            'name' => 'Channex — staging',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'credentials' => ['access_token' => 'test-key'],
            'import_reservations' => true,
        ]);
    }

    public function test_the_background_import_collects_and_acknowledges_channex_bookings(): void
    {
        // Linked, so the booking has somewhere to go — and is acknowledged
        // because it was saved, not because it was read.
        $property = Property::factory()->active()->create(['organization_id' => $this->organization->getKey(), 'currency' => 'USD']);
        $listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
            'currency' => 'USD',
        ]);
        ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $this->account->getKey(),
            'listing_id' => $listing->getKey(),
            'property_id' => $property->getKey(),
            'external_listing_id' => self::ROOM_TYPE,
            'is_active' => true,
            'metadata' => ['channex' => ['property_id' => self::PROPERTY]],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            '*/properties*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/api/v1/booking_revisions?*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/room_types*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/rate_plans*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/booking_revisions/feed*' => Http::response(['data' => [[
                'type' => 'booking_revision',
                'id' => 'rev-1',
                'attributes' => [
                    'id' => 'rev-1',
                    'property_id' => self::PROPERTY,
                    'ota_reservation_code' => 'BDC-1',
                    'status' => 'new',
                    'arrival_date' => now()->addDays(10)->toDateString(),
                    'departure_date' => now()->addDays(12)->toDateString(),
                    'amount' => '200.00',
                    'currency' => 'USD',
                    'rooms' => [['room_type_id' => self::ROOM_TYPE]],
                ],
            ]], 'meta' => ['total' => 1]]),
            '*/ack' => Http::response(['meta' => ['message' => 'Success']]),
        ]);

        // The same entry point the worker's one-minute loop calls.
        $this->artisan('channels:watch', ['--once' => true])->assertSuccessful();

        $this->assertSame('automatic', $this->account->fresh()->last_pull_result['trigger'] ?? null);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/booking_revisions/feed'));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/booking_revisions/rev-1/ack'));
    }

    public function test_the_connection_says_how_often_bookings_arrive(): void
    {
        $admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        $this->actingAsUser($admin, $this->organization)
            ->getJson('/api/v1/channels')
            ->assertOk()
            ->assertJsonPath('data.0.automatic_sync_interval_minutes', 5);
    }

    public function test_a_mapping_stopped_on_purpose_stays_stopped_after_an_import(): void
    {
        // Imports now run every hour on their own, so one that switched a
        // stopped mapping back on would resume selling a listing somebody had
        // taken off this channel, within the hour and without a word.
        $property = Property::factory()->active()->create(['organization_id' => $this->organization->getKey()]);
        $listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
        ]);

        $mapping = ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $this->account->getKey(),
            'listing_id' => $listing->getKey(),
            'property_id' => $property->getKey(),
            'external_listing_id' => self::ROOM_TYPE,
            'is_active' => false,
            'metadata' => ['channex' => ['property_id' => self::PROPERTY]],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            '*/properties*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            '*/room_types*' => Http::response(['data' => [[
                'type' => 'room_type',
                'id' => self::ROOM_TYPE,
                'attributes' => ['id' => self::ROOM_TYPE, 'title' => 'Twin Room'],
                'relationships' => ['property' => ['data' => ['id' => self::PROPERTY, 'type' => 'property']]],
            ]], 'meta' => ['total' => 1]]),
            '*/rate_plans*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
        ]);

        $this->app->make(ChannelListingImporter::class)->importFor($this->account);

        $this->assertFalse($mapping->fresh()->is_active);
    }
}
