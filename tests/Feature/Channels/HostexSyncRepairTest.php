<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Agents\Services\PropertyKnowledge;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelListingImporter;
use App\Domain\Channels\Services\ChannelPuller;
use App\Domain\Channels\Services\HostexReservationView;
use App\Domain\Channels\Services\ReservationImporter;
use App\Domain\Guests\Models\Guest;
use App\Domain\Guests\Services\GuestPortalService;
use App\Domain\Integrations\Providers\Channels\HostexChannelAdapter;
use App\Domain\Integrations\Support\HostexClient;
use App\Domain\Integrations\Support\HostexData;
use App\Domain\Pricing\Services\RevenueAnalytics;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Reservations\Models\Reservation;
use App\Http\Resources\PropertyResource;
use App\Http\Resources\ReservationResource;
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
                'reservations' => ['reservations' => [$this->row()], 'total' => 1],
                'transactions' => ['transactions' => [['id' => 71, 'property_id' => 101, 'reservation_code' => '0-100000-example', 'link_type' => 'reservation', 'direction' => 'income', 'amount' => 500, 'currency' => 'CAD', 'status' => 'received', 'item_name' => 'House fee']], 'total' => 1],
                default => [],
            };

            return Http::response(['error_code' => 200, 'data' => $data]);
        });
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
