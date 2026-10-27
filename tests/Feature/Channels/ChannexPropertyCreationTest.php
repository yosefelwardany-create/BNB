<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelPuller;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Support\RoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A pull that finds a Channex room type makes it a property here.
 *
 * Asked for directly: connecting Channex and pulling left a list of room types
 * and a button to press on each. Hostex has created properties on import for a
 * while; Channex now does the same, filled from what Channex holds about the
 * property the room type belongs to.
 */
class ChannexPropertyCreationTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY = '09a67eab-b052-46b3-b308-c7de8f493da7';

    private const DEMO_PROPERTY = '5648db98-e082-49e8-a428-2fd3250b47dd';

    private const TWIN = 'aaaa0000-0000-4000-8000-000000000001';

    private const DEMO_ROOM = 'aaaa0000-0000-4000-8000-000000000009';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'CAD']);
    }

    public function test_a_pull_creates_a_property_for_each_new_room_type(): void
    {
        $account = $this->account();
        $this->fakeChannex();

        $this->app->make(ChannelPuller::class)->pull($account);

        $mapping = $this->mappingFor(self::TWIN);
        $this->assertNotNull($mapping->property_id, 'The room type is linked to the property made for it.');
        $this->assertNotNull($mapping->listing_id);
        $this->assertTrue($mapping->is_active);

        $property = Property::query()->findOrFail($mapping->property_id);

        $this->assertSame('Twin Room', $property->name);
        $this->assertSame(2, (int) $property->max_occupancy);
        // The rate plan's currency, not the organization's.
        $this->assertSame('USD', $property->currency);
        // From the rate plan's default, so prices can be sent at once.
        $this->assertSame(10000, (int) $property->base_rate);
        // From Channex's property record, which the room type belongs to.
        $this->assertSame('Demo Street', $property->address_line_1);
        $this->assertSame('Demo Town', $property->city);
        $this->assertSame('GB', $property->country_code);

        // A draft, as Hostex's are: nothing starts selling here on its own.
        $this->assertNotSame('active', $property->status->value);
    }

    public function test_channexs_demo_property_is_left_out(): void
    {
        $account = $this->account();
        $this->fakeChannex();

        $outcome = $this->app->make(ChannelPuller::class)->pull($account);

        // Every staging account comes with it. Three junk properties on the
        // first pull is not what anybody connecting a channel wants.
        $this->assertNull($this->mappingFor(self::DEMO_ROOM)->property_id);
        $this->assertStringContainsString('demo', implode(' ', $outcome['properties']['issues'] ?? []));
    }

    public function test_pulling_again_does_not_make_a_second_property(): void
    {
        $account = $this->account();
        $this->fakeChannex();

        $this->app->make(ChannelPuller::class)->pull($account);
        $this->app->make(ChannelPuller::class)->pull($account->fresh());

        $this->assertSame(1, Property::query()->where('name', 'Twin Room')->count());
    }

    public function test_nothing_is_created_when_it_is_switched_off(): void
    {
        $account = $this->account(['auto_import_properties' => false]);
        $this->fakeChannex();

        $this->app->make(ChannelPuller::class)->pull($account);

        $this->assertNull($this->mappingFor(self::TWIN)->property_id);
        $this->assertSame(0, Property::query()->count());
    }

    public function test_it_is_on_for_a_new_connection_and_can_be_switched_off(): void
    {
        $admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        $created = $this->actingAsUser($admin, $this->organization)
            ->postJson('/api/v1/channels', [
                'channel' => 'channex',
                'name' => 'Channex',
                'credentials' => ['access_token' => 'test-key'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.auto_import_properties', true);

        $this->actingAsUser($admin, $this->organization)
            ->patchJson('/api/v1/channels/'.$created->json('data.id'), ['auto_import_properties' => false])
            ->assertOk()
            ->assertJsonPath('data.auto_import_properties', false);
    }

    public function test_a_connection_made_before_the_setting_existed_creates_properties(): void
    {
        // Connections stored before Channex had the setting carry no answer.
        // Creating is what was asked for, so that is what no answer means.
        $account = $this->account(null);

        $this->assertTrue(ChannelPuller::createsProperties($account));
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|null  $settings
     */
    private function account(?array $settings = ['auto_import_properties' => true]): ChannelAccount
    {
        return ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'channex',
            'name' => 'Channex — staging',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'credentials' => ['access_token' => 'test-key'],
            'import_reservations' => true,
            'settings' => $settings,
        ]);
    }

    private function mappingFor(string $roomType): ChannelListing
    {
        return ChannelListing::query()->where('external_listing_id', $roomType)->firstOrFail();
    }

    /**
     * The shapes staging returns: a property record with its address, room
     * types that point at it, and a rate plan whose primary option carries
     * the default price.
     */
    private function fakeChannex(): void
    {
        $property = fn (string $id, string $title, bool $demo): array => [
            'type' => 'property',
            'id' => $id,
            'attributes' => [
                'id' => $id,
                'title' => $title,
                'currency' => 'GBP',
                'address' => 'Demo Street',
                'city' => 'Demo Town',
                'country' => 'GB',
                'zip_code' => 'NW1 6XE',
                'latitude' => '51.5206646',
                'longitude' => '-0.1549994',
                'timezone' => 'Europe/London',
                'is_demo' => $demo,
            ],
            'relationships' => ['users' => ['data' => []]],
        ];

        $roomType = fn (string $id, string $title, string $propertyId): array => [
            'type' => 'room_type',
            'id' => $id,
            'attributes' => ['id' => $id, 'title' => $title, 'occ_adults' => 2, 'count_of_rooms' => 1],
            'relationships' => ['property' => ['data' => ['id' => $propertyId, 'type' => 'property']]],
        ];

        $page = fn (array $rows): array => ['data' => $rows, 'meta' => ['total' => count($rows), 'limit' => 100, 'page' => 1]];

        Http::fake([
            '*/properties*' => Http::response($page([
                $property(self::PROPERTY, 'Test Property', false),
                $property(self::DEMO_PROPERTY, 'Demo Property', true),
            ])),
            '*/room_types*' => Http::response($page([
                $roomType(self::TWIN, 'Twin Room', self::PROPERTY),
                $roomType(self::DEMO_ROOM, 'Demo Double', self::DEMO_PROPERTY),
            ])),
            '*/rate_plans*' => Http::response($page([[
                'type' => 'rate_plan',
                'id' => 'bbbb0000-0000-4000-8000-000000000001',
                'attributes' => [
                    'id' => 'bbbb0000-0000-4000-8000-000000000001',
                    'title' => 'Best Available Rate',
                    'currency' => 'USD',
                    'sell_mode' => 'per_room',
                    'options' => [['occupancy' => 2, 'is_primary' => true, 'rate' => '100.00']],
                ],
                'relationships' => [
                    'property' => ['data' => ['id' => self::PROPERTY, 'type' => 'property']],
                    'room_type' => ['data' => ['id' => self::TWIN, 'type' => 'room_type']],
                ],
            ]])),
            '*/booking_revisions/feed*' => Http::response($page([])),
        ]);
    }
}
