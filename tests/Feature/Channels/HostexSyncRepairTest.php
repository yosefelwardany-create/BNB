<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Agents\Services\PropertyKnowledge;
use App\Domain\Availability\DataObjects\AvailabilityRequest;
use App\Domain\Availability\Models\CalendarDay;
use App\Domain\Availability\Services\AvailabilityEngine;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Models\SyncJob;
use App\Domain\Channels\Services\ChannelListingImporter;
use App\Domain\Channels\Services\ChannelPuller;
use App\Domain\Channels\Services\HostexReservationView;
use App\Domain\Channels\Services\ReservationImporter;
use App\Domain\Guests\Models\Guest;
use App\Domain\Guests\Services\GuestPortalService;
use App\Domain\Integrations\Providers\Channels\HostexChannelAdapter;
use App\Domain\Integrations\Support\HostexClient;
use App\Domain\Integrations\Support\HostexData;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Pricing\Services\RevenueAnalytics;
use App\Domain\Properties\Models\Amenity;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Reservations\Models\Reservation;
use App\Http\Resources\PropertyResource;
use App\Http\Resources\ReservationResource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Synthetic fixtures follow the official v3 schemas; no real guest data. */
class HostexSyncRepairTest extends TestCase
{
    use RefreshDatabase;

    private function connection(): ChannelAccount
    {
        $org = $this->createOrganization(['base_currency' => 'CAD']);
        $this->actingAsUser($this->createUser($org), $org);

        return ChannelAccount::query()->create([
            'organization_id' => $org->id, 'channel' => 'hostex', 'name' => 'Fixture Hostex',
            'status' => 'connected', 'credentials' => ['access_token' => 'synthetic-token'],
            'collects_payment' => true, 'import_reservations' => true,
        ]);
    }

    private function mapped(ChannelAccount $account, string $external = '101'): Property
    {
        $property = app(PropertyService::class)->create(['name' => 'Local placeholder '.$external, 'property_type' => 'apartment', 'currency' => 'USD', 'internal_notes' => 'Keep my instructions']);
        ChannelListing::query()->create([
            'channel_account_id' => $account->id, 'external_listing_id' => $external,
            'property_id' => $property->id, 'listing_id' => $property->listings()->first()->id,
        ]);

        return $property;
    }

    private function row(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/hostex/reservation.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function import(ChannelAccount $account, ?array $row = null): Reservation
    {
        $payload = app(HostexChannelAdapter::class)->reservation($row ?? $this->row());

        return app(ReservationImporter::class)->importOne($account, $payload)['reservation'];
    }

    private function api(array $overrides = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($overrides) {
            $path = trim(parse_url($request->url(), PHP_URL_PATH), '/');
            $path = substr($path, 3);
            $data = $overrides[$path] ?? match ($path) {
                'properties' => ['properties' => [['id' => 101, 'title' => 'Source Lake House', 'address' => '1 Example Road', 'latitude' => '43.6000000', 'longitude' => '-79.4000000', 'channels' => [['listing_id' => '900001', 'channel_type' => 'airbnb', 'currency' => 'CAD']]]], 'total' => 1],
                'listings' => ['listings' => [['id' => 22, 'listing_id' => '900001', 'channel_type' => 'airbnb', 'title' => 'Source Lake House', 'url' => 'https://www.airbnb.com/rooms/900001', 'cover' => 'https://images.example.test/cover.jpg', 'shelf_status' => 'listed', 'metadata' => ['city' => 'Toronto', 'house_picture_list' => ['https://images.example.test/cover.jpg', 'https://images.example.test/gallery.jpg']]]], 'total' => 1],
                'listings/airbnb/price_and_rules' => ['listing_currency' => 'CAD', 'base_price' => 200, 'cleaning_fee' => 50, 'security_deposit' => 0, 'extra_guest_fee' => 0, 'check_in_start_time' => 15, 'check_out_before' => 11, 'minimum_stay' => 2, 'max_guests' => 2],
                'listings/calendar' => ['listings' => [['listing_id' => '900001', 'channel_type' => 'airbnb', 'calendar' => [['date' => now()->toDateString(), 'price' => 225, 'inventory' => 1]]]]],
                'availabilities' => ['properties' => [['id' => 101, 'availabilities' => [['date' => now()->toDateString(), 'available' => true, 'remarks' => '']]]]],
                'reservations' => ['reservations' => [$this->row()], 'total' => 1],
                'transactions' => ['transactions' => [['id' => 71, 'property_id' => 101, 'reservation_code' => '0-100000-example', 'link_type' => 'reservation', 'direction' => 'income', 'amount' => 500, 'currency' => 'CAD', 'status' => 'received', 'item_name' => 'House fee']], 'total' => 1],
                default => [],
            };

            return Http::response(['error_code' => 200, 'data' => $data]);
        });
    }

    public function test_one_pull_automatically_creates_and_fills_a_draft_property_without_duplicates_or_outbound_writes(): void
    {
        $account = $this->connection();
        $account->forceFill(['settings' => ['auto_import_properties' => true]])->save();
        $wifi = Amenity::query()->create(['key' => 'wifi', 'name' => 'Wi-Fi', 'category' => 'essentials']);
        $this->api(['listings' => ['listings' => [[
            'listing_id' => '900001', 'channel_type' => 'airbnb',
            'metadata' => ['city' => 'Toronto', 'country_name' => 'Canada', 'bedrooms' => 2, 'beds' => 3,
                'bathrooms' => 1.5, 'person_capacity' => 4, 'description' => 'A source description.',
                'house_rules' => 'No smoking.', 'amenities' => ['Wi-Fi'],
                'house_picture_list' => ['https://images.example.test/auto.jpg']],
        ]], 'total' => 1]]);

        $first = app(ChannelPuller::class)->pull($account, true);
        $this->assertSame('completed', $first['status'], json_encode($first));
        $this->assertSame(1, $first['properties']['created']);
        $this->assertSame(0, $first['listings']['unmapped']);
        $property = Property::query()->sole();
        $this->assertSame('draft', $property->status->value);
        $this->assertSame('America/Toronto', $property->timezone);
        $this->assertSame('CA', $property->country_code);
        $this->assertSame('CAD', $property->currency);
        $this->assertEquals(20000, $property->base_rate);
        $this->assertEquals(2, $property->bedrooms);
        $this->assertEquals(4, $property->max_occupancy);
        $this->assertSame('A source description.', $property->description);
        $this->assertSame('No smoking.', $property->house_rules);
        $this->assertSame([$wifi->id], $property->amenities()->pluck('amenities.id')->all());
        $this->assertSame('imported', $property->settings['hostex']['amenities_status']);
        $this->assertSame(1, Reservation::query()->count());

        $again = app(ChannelPuller::class)->pull($account, true);
        $this->assertSame('completed', $again['status']);
        $this->assertSame(0, $again['properties']['created']);
        $this->assertSame($property->id, Property::query()->sole()->id);
        $this->assertSame(1, PropertyPhoto::query()->count());
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' && ! str_contains($request->url(), '/listings/calendar'));
        $this->assertFalse($account->fresh()->sync_availability);
        $this->assertFalse($account->fresh()->sync_rates);
    }

    public function test_automatic_timezone_does_not_replace_a_manual_correction(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        $this->api();
        app(ChannelPuller::class)->pull($account, true);
        $this->assertSame('America/Toronto', $property->fresh()->timezone);
        app(PropertyService::class)->update($property->fresh(), ['timezone' => 'America/Vancouver']);
        app(ChannelPuller::class)->pull($account, true);
        $this->assertSame('America/Vancouver', $property->fresh()->timezone);
        $this->assertContains('timezone', $property->fresh()->settings['hostex_overrides']);
    }

    public function test_missing_amenities_are_reported_without_removing_local_choices(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        $wifi = Amenity::query()->create(['key' => 'wifi', 'name' => 'Wi-Fi', 'category' => 'essentials']);
        $property->amenities()->attach($wifi);
        $this->api();
        app(ChannelPuller::class)->pull($account, true);
        $this->assertSame('missing', $property->fresh()->settings['hostex']['amenities_status']);
        $this->assertSame([$wifi->id], $property->amenities()->pluck('amenities.id')->all());
    }

    public function test_hostex_amenity_enums_fill_catalogue_checks_and_keep_distinct_source_features(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        $wifi = Amenity::query()->create(['key' => 'wifi', 'name' => 'Wi-Fi', 'category' => 'essentials']);
        $this->api(['listings' => ['listings' => [[
            'listing_id' => '900001', 'channel_type' => 'airbnb', 'metadata' => [
                'amenity_list' => ['WIRELESS_INTERNET', 'BODY_SOAP', 'PATIO_OR_BELCONY'],
            ],
        ]], 'total' => 1]]);
        app(ChannelPuller::class)->pull($account, true);
        $first = $property->amenities()->pluck('amenities.id')->sort()->values()->all();
        $this->assertCount(3, $first);
        $this->assertContains($wifi->id, $first);
        $this->assertSame('imported', $property->fresh()->settings['hostex']['amenities_status']);
        $this->assertSame('Patio or balcony', Amenity::query()->where('key', 'hostex_patio_or_belcony')->sole()->name);
        $this->assertSame($property->organization_id, Amenity::query()->where('key', 'hostex_body_soap')->sole()->organization_id);
        app(ChannelPuller::class)->pull($account, true);
        $this->assertSame($first, $property->amenities()->pluck('amenities.id')->sort()->values()->all());
        $this->assertSame(3, Amenity::query()->count());
    }

    public function test_local_amenity_edits_survive_later_imports_and_unsupported_source_entries_are_not_guessed(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        $wifi = Amenity::query()->create(['key' => 'wifi', 'name' => 'Wi-Fi', 'category' => 'essentials']);
        $pool = Amenity::query()->create(['key' => 'pool', 'name' => 'Swimming pool', 'category' => 'outdoors']);
        $this->api(['listings' => ['listings' => [[
            'listing_id' => '900001', 'channel_type' => 'airbnb', 'metadata' => ['amenities' => ['Wi-Fi', ['id' => 999], ['name' => 'Swimming pool', 'available' => 'false']]],
        ]], 'total' => 1]]);
        app(ChannelPuller::class)->pull($account, true);
        $this->assertSame([$wifi->id], $property->amenities()->pluck('amenities.id')->all());
        $this->assertSame('partial', $property->fresh()->settings['hostex']['amenities_status']);
        app(PropertyService::class)->syncAmenities($property->fresh(), [$pool->id]);
        app(ChannelPuller::class)->pull($account, true);
        $this->assertSame([$pool->id], $property->amenities()->pluck('amenities.id')->all());
        $this->assertContains('amenities', $property->fresh()->settings['hostex_overrides']);
    }

    public function test_master_calendar_blocks_are_visible_and_prevent_new_sales_but_can_reopen_on_a_later_read(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        $date = now()->addDays(20)->toDateString();
        $this->api(['availabilities' => ['properties' => [['id' => 101, 'availabilities' => [
            ['date' => $date, 'available' => false, 'remarks' => 'private-source-remark'],
        ]]]]]);
        $result = app(ChannelPuller::class)->pull($account, true);
        $this->assertSame(1, $result['availability']['unavailable_days']);
        $this->assertStringNotContainsString('private-source-remark', json_encode($property->fresh()->settings));
        $listing = $property->listings()->first();
        $listing->forceFill(['status' => 'archived'])->save();
        $from = CarbonImmutable::parse($date);
        $to = $from->addDay();
        $calendar = $this->getJson('/api/v1/calendar?from='.$date.'&to='.$to->toDateString())->assertOk();
        $calendar->assertJsonPath('listings.0.days.0.source_available', false)
            ->assertJsonPath('listings.0.days.0.available', false)
            ->assertJsonPath('listings.0.days.0.sold_units', 0)
            ->assertJsonPath('listings.0.days.0.blocked_units', 1);
        $check = app(AvailabilityEngine::class)->check(new AvailabilityRequest(
            property: $property->fresh(), checkIn: $from, checkOut: $to, listing: $listing, ignorePropertyStatus: true,
        ));
        $this->assertFalse($check->isAvailable);
        $this->api(['availabilities' => ['properties' => [['id' => 101, 'availabilities' => [['date' => $date, 'available' => true]]]]]]);
        app(ChannelPuller::class)->pull($account, true);
        $this->getJson('/api/v1/calendar?from='.$date.'&to='.$to->toDateString())->assertOk()
            ->assertJsonPath('listings.0.days.0.source_available', true)
            ->assertJsonPath('listings.0.days.0.available', true);
        CalendarDay::query()->create(['listing_id' => $listing->id, 'calendar_date' => $date, 'is_blocked' => true]);
        $this->getJson('/api/v1/calendar?from='.$date.'&to='.$to->toDateString())->assertOk()
            ->assertJsonPath('listings.0.days.0.source_available', true)
            ->assertJsonPath('listings.0.days.0.manually_blocked', true)
            ->assertJsonPath('listings.0.days.0.available', false);
    }

    public function test_cached_diagnostics_show_price_inputs_and_photo_shapes_without_private_values_or_network_calls(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        $property->forceFill(['cleaning_fee' => 4500, 'settings' => ['hostex_overrides' => ['currency'], 'hostex' => ['applied' => ['currency' => 'USD']]]])->save();
        $mapping = ChannelListing::query()->sole();
        $mapping->forceFill(['metadata' => ['hostex' => [
            'price_rules' => ['listing_currency' => 'CAD', 'base_price' => 60, 'pet_fee' => ['private' => 'private-fee-note']],
            'cover' => 'https://private-images.example.test/private-path.jpg?token=private-signature',
            'listing_metadata' => ['house_picture_list' => [['image' => ['url' => 'https://private-images.example.test/photo.jpg?token=private-signature'], 'caption' => 'Private Guest Name']]],
        ]]])->save();
        Http::fake();
        $response = $this->getJson('/api/v1/channels/'.$account->id.'/diagnostics')->assertOk()
            ->assertJsonPath('data.mappings.0.pricing.local.cleaning_fee', 4500)
            ->assertJsonPath('data.mappings.0.pricing.local.currency', 'USD')
            ->assertJsonPath('data.mappings.0.pricing.source.base_price', 60)
            ->assertJsonPath('data.mappings.0.pricing.overrides.0', 'currency')
            ->assertJsonPath('data.mappings.0.images.cover.format', 'url')
            ->assertJsonPath('data.mappings.0.images.cover.has_query', true)
            ->assertJsonPath('data.mappings.0.images.gallery.fields.0.fields.image.fields.url.accepted_image_url', true);
        foreach (['synthetic-token', 'private-signature', 'private-path', 'private-images', 'Private Guest Name', 'private-fee-note', 'Keep my instructions'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertEquals(4500, $property->fresh()->cleaning_fee);
        $this->assertNull($account->fresh()->last_pull_attempted_at);
        Http::assertNothingSent();
    }

    public function test_diagnostics_are_account_tenant_scoped_and_require_manage_permission(): void
    {
        $account = $this->connection();
        $org = $account->organization;
        $viewer = $this->createUser($org, []);
        $this->actingAsUser($viewer, $org);
        $this->getJson('/api/v1/channels/'.$account->id.'/diagnostics')->assertForbidden();
        $this->connection();
        $this->getJson('/api/v1/channels/'.$account->id.'/diagnostics')->assertNotFound();
    }

    public function test_manual_push_cannot_bypass_an_import_only_hostex_connection(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $mapping = ChannelListing::query()->sole();
        Http::fake();
        $this->postJson('/api/v1/channel-listings/'.$mapping->id.'/push', ['what' => 'both'])
            ->assertOk()
            ->assertJsonPath('data.availability.performed', false)
            ->assertJsonPath('data.availability.error_code', 'push_disabled')
            ->assertJsonPath('data.rates.performed', false)
            ->assertJsonPath('data.rates.error_code', 'push_disabled');
        Http::assertNothingSent();
        $this->assertDatabaseCount('sync_jobs', 0);
    }

    public function test_full_pull_hydrates_the_exact_property_photos_prices_and_api_idempotently(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        $this->api();
        $puller = app(ChannelPuller::class);
        $result = $puller->pull($account, true);
        $this->assertSame('completed', $result['status'], json_encode($result));
        $first = Reservation::query()->sole();
        $puller->pull($account->fresh(), true);
        $property->refresh();
        $this->assertSame('Source Lake House', $property->name);
        $this->assertSame('1 Example Road', $property->address_line_1);
        $this->assertSame('Toronto', $property->city);
        $this->assertSame('Keep my instructions', $property->internal_notes);
        $this->assertSame('CAD', $property->currency);
        $this->assertEquals(20000, $property->base_rate);
        $this->assertSame('CAD', $property->listings()->first()->currency);
        $this->assertSame(2, PropertyPhoto::query()->count());
        $this->assertSame(1, Reservation::query()->count());
        $this->assertSame($first->id, Reservation::query()->sole()->id);
        $this->assertSame(1, Guest::query()->count());
        $this->assertSame(1, DB::table('hostex_transactions')->count());
        $this->assertDatabaseCount('payments', 0);
        $serialized = (new PropertyResource($property->load('photos')))->resolve(Request::create('/'));
        $this->assertSame('Source Lake House', $serialized['name']);
        $this->assertSame(22500, $serialized['hostex']['calendar'][0]['price']['amount']);
        $this->assertCount(2, $serialized['photos']);
        $this->assertNotNull($account->fresh()->last_pull_succeeded_at);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET' && ! str_ends_with($r->url(), '/listings/calendar'));
    }

    public function test_blank_usd_property_adopts_sixty_cad_despite_shared_unrelated_or_zero_pricing_rules(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        $other = app(PropertyService::class)->create(['name' => 'Other property', 'property_type' => 'house']);
        foreach ([[], ['listing_id' => $other->listings()->first()->id], ['property_id' => $property->id, 'adjustment_value' => 0, 'floor_rate' => 0]] as $scope) {
            PricingRule::query()->create(array_replace(['name' => 'Fixture rate rule', 'kind' => 'custom', 'adjustment_type' => 'increase_fixed', 'adjustment_value' => 100], $scope));
        }
        $this->api(['listings/airbnb/price_and_rules' => ['listing_currency' => 'CAD', 'base_price' => 60]]);
        app(ChannelPuller::class)->pull($account, true);
        $this->getJson('/api/v1/properties/'.$property->id)->assertOk()
            ->assertJsonPath('data.currency', 'CAD')
            ->assertJsonPath('data.pricing.base_rate.amount', 6000)
            ->assertJsonPath('data.pricing.base_rate.currency', 'CAD');
        $this->assertSame('CAD', $property->listings()->first()->currency);
        app(ChannelPuller::class)->pull($account->fresh(), true);
        $this->assertEquals(6000, $property->fresh()->base_rate);
    }

    public function test_property_scoped_nonzero_pricing_is_not_silently_relabelled(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        PricingRule::query()->create(['name' => 'Local rate', 'kind' => 'base_rate', 'property_id' => $property->id, 'adjustment_type' => 'set', 'adjustment_value' => 12300]);
        $this->api();
        app(ChannelPuller::class)->pull($account, true);
        $this->assertSame('USD', $property->fresh()->currency);
        $this->assertEquals(12300, PricingRule::query()->sole()->adjustment_value);
    }

    public function test_archived_listing_keeps_its_usd_override_without_blocking_the_property_source_price(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        $listing = $property->listings()->first();
        $listing->forceFill(['status' => 'archived', 'currency' => 'USD', 'base_rate' => 5000, 'cleaning_fee' => null])->save();
        $this->api(['listings/airbnb/price_and_rules' => ['listing_currency' => 'CAD', 'base_price' => 60, 'cleaning_fee' => 25]]);
        $result = app(ChannelPuller::class)->pull($account, true);
        $this->assertSame(0, $result['properties']['failed']);
        $this->assertSame('CAD', $property->fresh()->currency);
        $this->assertEquals(6000, $property->fresh()->base_rate);
        $this->assertSame('USD', $listing->fresh()->currency);
        $this->assertEquals(5000, $listing->fresh()->base_rate);
        $this->assertEquals(0, $listing->fresh()->cleaning_fee);
        $this->assertEquals(2500, $property->fresh()->cleaning_fee);
        $this->assertSame('archived', $listing->fresh()->status->value);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET' && ! str_ends_with($r->url(), '/listings/calendar'));
    }

    public function test_active_listing_price_still_protects_its_currency(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        $property->listings()->first()->forceFill(['base_rate' => 5000])->save();
        $this->api();
        $result = app(ChannelPuller::class)->pull($account, true);
        $this->assertSame(1, $result['properties']['failed']);
        $this->assertSame('USD', $property->fresh()->currency);
    }

    public function test_observed_photo_variants_and_json_cover_import_once_and_preserve_local_captions(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        // Field structure observed in the signed-in app; all values synthetic.
        $photo = ['id' => 123, 'order' => 1, 'caption' => 'Room overview',
            'original_url' => 'https://images.example.test/room.jpg',
            'large_url' => 'https://images.example.test/room.jpg?width=720',
            'small_url' => 'https://images.example.test/room.jpg?width=240',
            'extra_large_url' => 'https://images.example.test/room.jpg?width=1200',
            'extra_extra_large_url' => 'https://images.example.test/room.jpg?width=1920'];
        $this->api(['listings' => ['listings' => [['listing_id' => '900001', 'channel_type' => 'airbnb',
            'cover' => json_encode($photo), 'metadata' => ['house_picture_list' => [$photo]]]]]]);
        $result = app(ChannelPuller::class)->pull($account, true);
        $this->assertSame(0, $result['properties']['failed']);
        $this->assertSame(1, $result['properties']['photos']);
        app(ChannelPuller::class)->pull($account->fresh(), true);
        $this->assertDatabaseCount('property_photos', 1);
        $saved = PropertyPhoto::query()->sole();
        $this->assertSame($photo['original_url'], $saved->url());
        $this->assertSame('Room overview', $saved->caption);
        $this->assertTrue($saved->is_cover);
        $saved->forceFill(['caption' => 'Local caption'])->save();
        app(ChannelPuller::class)->pull($account->fresh(), true);
        $this->assertSame('Local caption', $saved->fresh()->caption);
        $this->assertNull(HostexData::pictureUrl('{"original_url":"http://127.0.0.1/private"}'));
    }

    public function test_photo_metadata_and_protocol_relative_urls_import_once_and_errors_are_grouped(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $listing = ['listing_id' => '900001', 'channel_type' => 'airbnb', 'cover' => '//images.example.test/cover.jpg',
            'metadata' => ['house_picture_list' => array_merge([
                ['asset' => ['location' => 'https://images.example.test/gallery.jpg'], 'width' => 1920],
                ['asset' => ['location' => 'https://images.example.test/gallery.jpg']],
                'http://images.example.test/third.jpg',
            ], array_fill(0, 78, ['width' => 1200]))]];
        $this->api(['listings' => ['listings' => [$listing]]]);
        $result = app(ChannelPuller::class)->pull($account, true);
        $this->assertSame(3, $result['properties']['photos']);
        $this->assertSame(78, $result['properties']['failed']);
        $this->assertCount(1, $result['properties']['issues']);
        $this->assertStringContainsString('78 image entries', $result['properties']['issues'][0]);
        app(ChannelPuller::class)->pull($account->fresh(), true);
        $this->assertDatabaseCount('property_photos', 3);
        $this->assertSame('https://images.example.test/cover.jpg', PropertyPhoto::query()->where('is_cover', true)->sole()->url());
        $this->assertNull(HostexData::pictureUrl(['a' => 'https://images.example.test/a.jpg', 'b' => 'https://images.example.test/b.jpg']));
        $this->assertNull(HostexData::pictureUrl(['a' => ['https://images.example.test/a.jpg', 'https://images.example.test/b.jpg'], 'b' => 'https://images.example.test/c.jpg']));
        $this->assertNull(HostexData::pictureUrl(['image' => 'http://127.0.0.1/private']));
    }

    public function test_rejected_history_request_falls_back_to_small_windows_and_repairs_legacy_guest_and_booking(): void
    {
        $account = $this->connection();
        $account->forceFill(['settings' => ['hostex_reservations_from' => '2026-01-01', 'hostex_reservations_to' => '2027-01-01']])->save();
        $this->mapped($account);
        $reservation = $this->import($account);
        $guestId = $reservation->guest_id;
        $reservation->guest->forceFill(['first_name' => 'Guest', 'last_name' => null, 'display_name' => 'Guest', 'email' => null, 'phone' => null])->save();
        $reservation->forceFill(['external_reservation_id' => $this->row()['reservation_code'], 'hostex_reservation_code' => null, 'external_confirmation_code' => null, 'source_metadata' => []])->save();
        Http::fake(function ($request) {
            $from = CarbonImmutable::parse($request['start_check_out_date']);
            $to = CarbonImmutable::parse($request['end_check_out_date']);

            return $from->diffInDays($to) >= 180
                ? Http::response(['error_code' => 400, 'error_msg' => 'Sensitive provider content must not be returned'])
                : Http::response(['error_code' => 200, 'data' => ['reservations' => [$this->row()], 'total' => 1]]);
        });
        $counts = app(ReservationImporter::class)->importFor($account);
        $this->assertSame(0, $counts['failed']);
        $this->assertSame(1, $counts['updated']);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('guests', 1);
        $this->assertSame($guestId, $reservation->fresh()->guest_id);
        $this->assertSame('Example Visitor', $reservation->fresh()->guest->display_name);
        $this->assertSame('HMTEST1234', $reservation->fresh()->external_confirmation_code);
        $this->getJson('/api/v1/guests')->assertOk()->assertJsonPath('data.0.display_name', 'Example Visitor');
        Http::assertSentCount(4);
    }

    public function test_reservation_errors_keep_safe_code_coverage_and_retryability_without_raw_data(): void
    {
        $account = $this->connection();
        Http::fakeSequence()->push(['error_code' => 403, 'error_msg' => 'private token and guest data']);
        $counts = app(ReservationImporter::class)->importFor($account);
        $this->assertSame(1, $counts['failed']);
        $this->assertStringContainsString('403 on GET reservations', $counts['issues'][0]);
        $this->assertStringNotContainsString('private', json_encode($counts));
        $this->assertNotEmpty($counts['coverage']['start_check_out_date']);
        $this->assertFalse(SyncJob::query()->sole()->is_retryable);
        Http::assertSentCount(1);
    }

    public function test_real_reference_guest_and_financial_meanings_are_preserved(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $reservation = $this->import($account);
        $api = (new ReservationResource($reservation->load('guest')))->resolve(Request::create('/'));
        $this->assertSame('HMTEST1234', $api['display_reference']);
        $this->assertNotSame($api['confirmation_code'], $api['display_reference']);
        $this->assertSame('0-100000_1-example', $api['external_reservation_id']);
        $this->assertSame('Example Visitor', $api['guest']->resource->display_name);
        $this->assertSame(60000, $api['financials']['accommodation']['amount']);
        $this->assertSame(20000, $api['financials']['average_daily_rate']['amount']);
        $this->assertSame(70525, $api['financials']['grand_total']['amount']);
        $this->assertNull($api['financials']['paid']);
        $this->assertNull($api['financials']['balance_due']);
        $this->assertNull($api['hostex']['financials']['host_payout']);
        $this->assertSame(50000, $api['hostex']['financials']['payment']['received_amount']['amount']);
        $this->assertSame(86818, $api['hostex']['financials']['payment']['balance_amount']['amount']);
        $this->assertEquals(60000, $reservation->stayNights()->sum('rate_amount'));
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_same_dates_update_guest_reference_and_money_and_partial_payload_preserves_them(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $row = $this->row();
        unset($row['guest_name'], $row['guest_email'], $row['guest_phone'], $row['guests'], $row['channel_id'], $row['rates'], $row['payment']);
        $first = $this->import($account, $row);
        $this->assertSame('Guest', $first->guest()->first()->display_name);
        $this->assertNull($first->grand_total);
        $fixed = $this->import($account);
        $this->assertSame($first->id, $fixed->id);
        $this->assertSame($first->guest_id, $fixed->guest_id);
        $this->assertSame('Example Visitor', $fixed->guest()->first()->display_name);
        $partial = $this->import($account, $row);
        $this->assertEquals(70525, $partial->grand_total);
        $this->assertSame('HMTEST1234', $partial->external_confirmation_code);
        $this->assertSame('Example Visitor', $partial->guest()->first()->display_name);
        $this->assertDatabaseCount('guests', 1);
    }

    public function test_unknown_money_and_currency_stay_unavailable_and_do_not_unlock_guest_access(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $row = $this->row();
        $row['rates'] = ['rate' => ['amount' => '100.00']];
        unset($row['payment']);
        $reservation = $this->import($account, $row);
        $view = HostexReservationView::for($reservation);
        $this->assertNull($view['financials']['reservation_rate']['currency']);
        $this->assertNull($view['financials']['reservation_rate']['amount']);
        $this->assertNull($reservation->grand_total);
        $this->assertNull($reservation->balance_due);
        $this->assertSame(0, $reservation->stayNights()->count());
        $this->assertContains('Payment has not been independently verified for this channel booking.', app(PropertyKnowledge::class)->reasonsToWithhold($reservation));
    }

    public function test_foreign_currency_and_zero_decimal_units_are_not_relabelled_cad(): void
    {
        $this->assertSame(100, HostexData::amount('100', 'JPY')['amount']);
        $this->assertSame(1001, HostexData::amount('1.001', 'BHD')['amount']);
        $this->assertSame(10001, HostexData::amount('100.005', 'USD')['amount']);
        $account = $this->connection();
        $this->mapped($account);
        $row = $this->row();
        $row['rates']['rate']['currency'] = 'USD';
        $reservation = $this->import($account, $row);
        $this->assertSame('USD', $reservation->currency);
        $this->assertSame('USD', HostexReservationView::for($reservation)['financials']['reservation_rate']['currency']);
        $this->assertSame('CAD', HostexReservationView::for($reservation)['financials']['accommodation']['currency']);
    }

    public function test_multiple_stays_share_an_order_without_merging_or_duplicating_payments(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $this->mapped($account, '102');
        $this->import($account);
        $row = $this->row();
        $row['stay_code'] = '0-100000_2-example';
        $row['property_id'] = 102;
        $this->import($account, $row);
        $this->import($account, $row);
        $this->assertDatabaseCount('reservations', 2);
        $this->assertDatabaseCount('guests', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(2, Reservation::query()->where('hostex_reservation_code', '0-100000-example')->count());
    }

    public function test_name_match_does_not_map_a_hostex_property(): void
    {
        $account = $this->connection();
        app(PropertyService::class)->create(['name' => 'Source Lake House', 'property_type' => 'apartment']);
        $this->api();
        app(ChannelListingImporter::class)->importFor($account);
        $this->assertNull(ChannelListing::query()->sole()->property_id);
    }

    public function test_source_omissions_and_local_overrides_survive_repeated_pulls(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        $this->api();
        app(ChannelPuller::class)->pull($account, true);
        $property->refresh()->forceFill(['name' => 'My chosen name', 'base_rate' => 25000])->save();
        app(ChannelPuller::class)->pull($account->fresh(), true);
        $property->refresh();
        $this->assertSame('My chosen name', $property->name);
        $this->assertEquals(25000, $property->base_rate);
        $this->assertContains('name', $property->settings['hostex_overrides']);
        $this->api(['properties' => ['properties' => [['id' => 101, 'title' => 'New source title']]]]);
        app(ChannelListingImporter::class)->importFor($account);
        $this->assertSame('1 Example Road', $property->fresh()->address_line_1);
        $this->assertDatabaseCount('property_photos', 2);
    }

    public function test_failures_and_date_coverage_are_visible_without_advancing_success_timestamp(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $this->api();
        app(ChannelPuller::class)->pull($account, true);
        $lastSuccess = $account->fresh()->last_pull_succeeded_at;
        $bad = $this->row();
        $bad['stay_code'] = 'bad';
        $bad['property_id'] = 0;
        $this->api(['reservations' => ['reservations' => [$this->row(), $bad]]]);
        $result = app(ChannelPuller::class)->pull($account->fresh(), true);
        $this->assertSame('partial', $result['status']);
        $this->assertGreaterThan(0, $result['reservations']['failed']);
        $this->assertNotEmpty($result['reservations']['coverage']['start_check_out_date']);
        $this->assertEquals($lastSuccess, $account->fresh()->last_pull_succeeded_at);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_pagination_keeps_earlier_pages_when_later_page_fails(): void
    {
        $account = $this->connection();
        $rows = array_fill(0, 100, $this->row());
        Http::fakeSequence()->push(['error_code' => 200, 'data' => ['reservations' => $rows, 'total' => 101]])->push(['error_code' => 403]);
        $adapter = new HostexChannelAdapter;
        $this->assertCount(100, $adapter->importReservations($account));
        $this->assertCount(1, $adapter->readIssues);
        Http::assertSent(fn ($request) => $request['offset'] === 100 && isset($request['start_check_out_date'], $request['end_check_out_date']));
    }

    public function test_cancelled_stay_refreshes_data_and_does_not_create_accounting_entries(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $first = $this->import($account);
        $row = $this->row();
        $row['status'] = 'cancelled';
        $row['cancelled_at'] = '2026-10-01T12:00:00Z';
        $row['guest_name'] = 'Updated Visitor';
        $cancelled = $this->import($account, $row);
        $this->assertSame($first->id, $cancelled->id);
        $this->assertFalse($cancelled->blocksInventory());
        $this->assertSame('Updated Visitor', $cancelled->guest()->first()->display_name);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_identical_external_ids_are_scoped_to_account_and_tenant(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $first = $this->import($account);
        $other = $account->replicate();
        $other->save();
        $this->mapped($other);
        $second = $this->import($other);
        $this->assertNotSame($first->id, $second->id);
        $foreign = $this->connection();
        $this->mapped($foreign);
        $third = $this->import($foreign);
        $this->assertNotSame($second->id, $third->id);
        $this->assertSame(1, Reservation::query()->count());
        $this->assertSame(1, Guest::query()->count());
    }

    public function test_mapping_a_discovered_property_hydrates_cached_details_and_photos_over_http(): void
    {
        $account = $this->connection();
        $property = app(PropertyService::class)->create(['name' => 'Existing local property', 'property_type' => 'apartment']);
        $this->api(['reservations' => ['reservations' => []]]);
        app(ChannelPuller::class)->pull($account, true);
        $mappingId = ChannelListing::query()->sole()->id;
        $payload = ['channel_account_id' => $account->id, 'listing_id' => $property->listings()->first()->id, 'external_listing_id' => '101'];
        $this->postJson('/api/v1/channel-listings', $payload)->assertCreated()->assertJsonPath('data.id', $mappingId);
        $this->postJson('/api/v1/channel-listings', $payload)->assertCreated();
        ChannelListing::query()->sole()->forceFill(['is_active' => false])->save();
        $this->postJson('/api/v1/channel-listings', $payload)->assertCreated();
        $this->assertFalse(ChannelListing::query()->sole()->is_active);
        $this->assertDatabaseCount('properties', 1);
        $this->assertDatabaseCount('channel_listings', 1);
        $this->assertDatabaseCount('property_photos', 2);
        $this->getJson('/api/v1/properties')->assertOk()
            ->assertJsonPath('data.0.name', 'Source Lake House')
            ->assertJsonPath('data.0.pricing.base_rate.currency', 'CAD')
            ->assertJsonPath('data.0.pricing.base_rate.amount', 20000)
            ->assertJsonCount(2, 'data.0.photos');
    }

    public function test_legacy_order_keyed_reservation_keeps_its_internal_and_guest_ids(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $reservation = $this->import($account);
        $guestId = $reservation->guest_id;
        $internal = $reservation->confirmation_code;
        $reservation->forceFill(['external_reservation_id' => $reservation->hostex_reservation_code, 'hostex_reservation_code' => null])->save();
        $fixed = $this->import($account);
        $this->assertSame($reservation->id, $fixed->id);
        $this->assertSame($guestId, $fixed->guest_id);
        $this->assertSame($internal, $fixed->confirmation_code);
        $this->assertSame($this->row()['stay_code'], $fixed->external_reservation_id);
        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_a_competing_worker_holding_the_pull_lock_prevents_remote_requests(): void
    {
        $account = $this->connection();
        config(['database.connections.hostex_lock_test' => config('database.connections.pgsql')]);
        $other = DB::connection('hostex_lock_test');
        $key = 'channel-pull:'.$account->organization_id.':'.$account->id;
        $other->table('cache_locks')->insert(['key' => $key, 'owner' => 'competing-worker', 'expiration' => now()->timestamp + 7200]);
        Http::fake();
        try {
            $result = app(ChannelPuller::class)->pull($account, true);
            $this->assertSame('running', $result['status']);
            Http::assertNothingSent();
        } finally {
            $other->table('cache_locks')->where('key', $key)->where('owner', 'competing-worker')->delete();
            DB::purge('hostex_lock_test');
        }
    }

    public function test_partial_payment_updates_preserve_omitted_source_fields(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $this->import($account);
        $row = $this->row();
        $row['payment'] = ['currency' => 'CAD', 'received_amount' => 600];
        $source = HostexReservationView::for($this->import($account, $row));
        $this->assertSame(60000, $source['financials']['payment']['received_amount']['amount']);
        $this->assertSame(86818, $source['financials']['payment']['balance_amount']['amount']);
        $this->assertNull($source['financials']['host_payout']);
    }

    public function test_an_expired_pull_lease_is_reclaimed_and_released_after_completion(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $key = 'channel-pull:'.$account->organization_id.':'.$account->id;
        DB::table('cache_locks')->insert(['key' => $key, 'owner' => 'stopped-worker', 'expiration' => now()->timestamp - 1]);
        $this->api();
        $this->assertSame('completed', app(ChannelPuller::class)->pull($account, true)['status']);
        $this->assertFalse(DB::table('cache_locks')->where('key', $key)->exists());
    }

    public function test_malformed_reservation_does_not_discard_other_valid_rows(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $bad = $this->row();
        $bad['check_in_date'] = 'not-a-date';
        $this->api(['reservations' => ['reservations' => [$bad, $this->row()]]]);
        $counts = app(ReservationImporter::class)->importFor($account);
        $this->assertSame(1, $counts['failed']);
        $this->assertSame(1, $counts['imported']);
    }

    public function test_signed_photo_renewal_deduplicates_but_query_image_ids_remain_distinct(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $this->api();
        app(ChannelPuller::class)->pull($account, true);
        $listing = ['listing_id' => '900001', 'channel_type' => 'airbnb', 'cover' => 'https://images.example.test/cover.jpg?token=renewed',
            'metadata' => ['house_picture_list' => ['https://images.example.test/photo?id=1', 'https://images.example.test/photo?id=2', 'http://127.0.0.1/private']]];
        $this->api(['listings' => ['listings' => [$listing]]]);
        $outcome = app(ChannelPuller::class)->pull($account->fresh(), true);
        $this->assertSame('partial', $outcome['status']);
        $this->assertDatabaseCount('property_photos', 4);
        $this->assertSame(1, PropertyPhoto::query()->where('is_cover', true)->count());
    }

    public function test_missing_payment_stays_unknown_in_guest_portal_and_address_is_withheld(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        $property->forceFill(['address_line_1' => 'Private arrival address'])->save();
        $reservation = $this->import($account);
        $portal = app(GuestPortalService::class)->overview($reservation->load('property'));
        $this->assertSame('HMTEST1234', $portal['confirmation_code']);
        $this->assertNull($portal['grand_total']);
        $this->assertNull($portal['balance_due']);
        $this->assertNull($portal['is_paid_in_full']);
        $this->assertNull($portal['property']['address']);
    }

    public function test_aggregate_revenue_refuses_to_label_usd_nights_as_cad(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        $row = $this->row();
        $row['rates']['rate']['currency'] = 'USD';
        foreach ($row['rates']['details'] as &$detail) {
            $detail['currency'] = 'USD';
        } unset($detail);
        $reservation = $this->import($account, $row);
        $this->assertSame('USD', HostexReservationView::for($reservation)['financials']['accommodation']['currency']);
        $this->expectException(ValidationException::class);
        app(RevenueAnalytics::class)->summary($reservation->check_in_date, $reservation->check_out_date, [$property->id]);
    }

    public function test_source_transaction_snapshots_are_isolated_from_other_tenants(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $this->api();
        app(ChannelPuller::class)->pull($account, true);
        $this->getJson('/api/v1/hostex-transactions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.money.currency', 'CAD');
        $this->connection();
        $this->getJson('/api/v1/hostex-transactions')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_local_currency_override_does_not_receive_source_amounts_in_another_currency(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        $this->api();
        app(ChannelPuller::class)->pull($account, true);
        $property->refresh()->forceFill(['currency' => 'USD', 'base_rate' => 12345])->save();
        app(ChannelPuller::class)->pull($account->fresh(), true);
        $this->assertSame('USD', $property->fresh()->currency);
        $this->assertEquals(12345, $property->fresh()->base_rate);
        $this->assertSame('CAD', $property->fresh()->settings['hostex']['price_rules']['listing_currency']);
    }

    public function test_a_source_currency_change_cannot_relabel_omitted_old_prices(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        $this->api();
        app(ChannelPuller::class)->pull($account, true);
        $this->api(['listings/airbnb/price_and_rules' => ['listing_currency' => 'USD']]);
        app(ChannelPuller::class)->pull($account->fresh(), true);
        $property->refresh();
        $this->assertSame('CAD', $property->currency);
        $this->assertEquals(20000, $property->base_rate);
        $this->assertArrayNotHasKey('base_price', $property->settings['hostex']['price_rules']);
        $this->assertSame('USD', $property->settings['hostex']['price_rules']['listing_currency']);
    }

    public function test_reads_retry_transient_failures_but_never_retry_writes(): void
    {
        Sleep::fake();
        Http::fakeSequence()->push(['error_code' => 429], 200, ['Retry-After' => '1'])->push(['error_code' => 200, 'data' => ['properties' => []]]);
        $this->assertSame(['properties' => []], (new HostexClient('synthetic'))->get('properties'));
        Http::assertSentCount(2);
    }

    public function test_pull_preserves_a_paused_mapping_while_refreshing_inbound_data(): void
    {
        $account = $this->connection();
        $property = $this->mapped($account);
        ChannelListing::query()->sole()->forceFill(['is_active' => false])->save();
        $this->api(['listings/airbnb/price_and_rules' => ['listing_currency' => 'CAD', 'base_price' => 200, 'check_in_end_time' => 22, 'instant_booking' => true]]);
        app(ChannelPuller::class)->pull($account, true);
        $this->assertFalse(ChannelListing::query()->sole()->is_active);
        $this->assertSame('Source Lake House', $property->fresh()->name);
        $this->assertSame('22:00:00', $property->fresh()->check_in_until);
        $this->assertTrue($property->fresh()->instant_book);
    }

    public function test_recognized_revenue_is_preserved_and_only_mismatches_require_review(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $reservation = $this->import($account);
        $reservation->stayNights()->update(['recognised_at' => now()]);
        $unchanged = $this->import($account);
        $this->assertFalse($unchanged->source_metadata['hostex']['requires_accounting_review'] ?? false);
        $night = $reservation->stayNights()->first();
        $night->forceFill(['rate_amount' => 99999])->save();
        $changed = $this->import($account);
        $this->assertTrue($changed->source_metadata['hostex']['requires_accounting_review']);
        $this->assertEquals(99999, $night->fresh()->rate_amount);
    }

    public function test_reactivated_source_stay_clears_stale_cancellation_details(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $row = $this->row();
        $row['status'] = 'cancelled';
        $cancelled = $this->import($account, $row);
        $this->assertNotNull($cancelled->cancelled_at);
        $active = $this->import($account);
        $this->assertSame($cancelled->id, $active->id);
        $this->assertTrue($active->blocksInventory());
        $this->assertNull($active->cancelled_at);
        $this->assertNull($active->cancelled_by);
    }

    public function test_source_guest_details_and_unknown_counts_are_exposed_without_identity_documents(): void
    {
        $account = $this->connection();
        $this->mapped($account);
        $row = $this->row();
        unset($row['number_of_guests'], $row['number_of_adults'], $row['number_of_children'], $row['number_of_infants'], $row['number_of_pets']);
        $row['guests'][] = ['id' => 5002, 'name' => 'Example Companion', 'email' => 'companion@example.test', 'country' => 'CA', 'is_booker' => false, 'document_number' => 'synthetic-document'];
        $reservation = $this->import($account, $row);
        $api = (new ReservationResource($reservation))->resolve(Request::create('/'));
        $this->assertNull($api['guests']['adults']);
        $this->assertNull($api['guests']['total']);
        $this->assertNull($api['cancellation']['refund']);
        $this->assertSame('Example Companion', $api['hostex']['guest_details'][1]['name']);
        $this->assertStringNotContainsString('synthetic-document', json_encode($reservation->source_metadata));
        $this->assertNull(app(GuestPortalService::class)->overview($reservation)['adults']);
        unset($row['guests']);
        $this->assertCount(2, HostexReservationView::for($this->import($account, $row))['guest_details']);
    }
}
