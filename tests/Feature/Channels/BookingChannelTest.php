<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Support\RoleRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Where a guest booked: the booking website, Airbnb, Booking.com, by hand.
 *
 * A booking brought in through Channex is Channex's to transport but the
 * guest made it on an OTA, and Channex says which. Habitat keeps that name,
 * so the calendar and the bookings list can say "Airbnb" rather than
 * "Channel manager".
 */
class BookingChannelTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY = '716305c4-561a-4561-a187-7f5b8aeb5920';

    private const ROOM_TYPE = '994d1375-dbbd-4072-8724-b2ab32ce781b';

    private Organization $organization;

    private ChannelAccount $account;

    /** @var list<array<string, mixed>> what Channex's booking feed holds now */
    private array $feed = [];

    private bool $faked = false;

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

        $this->app->make(TenantContext::class)->clear();
    }

    public function test_a_channex_booking_keeps_the_site_it_was_made_on(): void
    {
        $this->importRevision('rev-1', ['ota_name' => 'BookingCom']);

        $reservation = $this->withoutScope(fn () => Reservation::query()->sole());
        $this->assertSame('BookingCom', $reservation->source_metadata['booking_channel'] ?? null);
        $this->assertSame('booking.com', $reservation->bookingChannel());

        $staff = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        $this->actingAsUser($staff, $this->organization)
            ->getJson(sprintf('/api/v1/calendar?from=%s&to=%s', now()->addDays(5)->toDateString(), now()->addDays(20)->toDateString()))
            ->assertOk()
            ->assertJsonPath('reservations.0.channel', 'booking.com')
            ->assertJsonPath('reservations.0.channel_name', 'BookingCom');

        $this->actingAsUser($staff, $this->organization)
            ->getJson('/api/v1/reservations/'.$reservation->getKey())
            ->assertOk()
            ->assertJsonPath('data.booking_channel', 'booking.com');
    }

    public function test_a_booking_brought_in_before_learns_its_site_when_channex_sends_it_again(): void
    {
        $this->importRevision('rev-1', []);
        $this->assertSame('channel_manager', $this->withoutScope(fn () => Reservation::query()->sole())->bookingChannel());

        // The same booking, unchanged, with the site Channex now names.
        $this->importRevision('rev-2', ['ota_name' => 'Airbnb']);

        $this->assertSame('airbnb', $this->withoutScope(fn () => Reservation::query()->sole())->bookingChannel());
    }

    public function test_every_booking_is_named_by_where_the_guest_made_it(): void
    {
        $named = fn (array $attributes): string => (new Reservation)->forceFill($attributes)->bookingChannel();

        $this->assertSame('website', $named(['source' => 'website']));
        $this->assertSame('direct', $named(['source' => 'direct']));
        $this->assertSame('channel_manager', $named(['source' => 'channex']));
        $this->assertSame('airbnb', $named(['source' => 'hostex', 'source_metadata' => ['hostex' => ['channel_type' => 'airbnb']]]));

        foreach ([
            'Airbnb' => 'airbnb',
            'BookingCom' => 'booking.com',
            'Booking.com' => 'booking.com',
            'Expedia' => 'expedia',
            'VRBO' => 'vrbo',
            'HomeAway' => 'vrbo',
            'Agoda' => 'agoda',
            // Channex's own test app for bookings: not Booking.com.
            'Booking CRS' => 'booking_crs',
        ] as $site => $key) {
            $this->assertSame($key, $named(['source' => 'channex', 'source_metadata' => ['booking_channel' => $site]]), $site);
        }
    }

    /**
     * One Channex revision for the same booking, read by the background
     * import, as Channex sends it.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function importRevision(string $revisionId, array $attributes): void
    {
        $this->feed = [[
            'type' => 'booking_revision',
            'id' => $revisionId,
            'attributes' => $attributes + [
                'id' => $revisionId,
                'booking_id' => 'bk-1',
                'property_id' => self::PROPERTY,
                'ota_reservation_code' => 'OTA-1',
                'status' => 'new',
                'arrival_date' => now()->addDays(10)->toDateString(),
                'departure_date' => now()->addDays(12)->toDateString(),
                'amount' => '200.00',
                'currency' => 'USD',
                'rooms' => [['room_type_id' => self::ROOM_TYPE]],
            ],
        ]];

        if (! $this->faked) {
            // Faked once: a later fake would not replace the feed above.
            $this->faked = true;
            Http::preventStrayRequests();
            Http::fake([
                '*/properties*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
                '*/api/v1/booking_revisions?*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
                '*/room_types*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
                '*/rate_plans*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
                '*/booking_revisions/feed*' => fn () => Http::response(['data' => $this->feed, 'meta' => ['total' => count($this->feed)]]),
                '*/ack' => Http::response(['meta' => ['message' => 'Success']]),
            ]);
        }

        // Each run is a fresh pull, as the next five-minute import would be.
        $this->account->refresh()->forceFill(['last_pull_attempted_at' => null, 'last_pull_result' => null])->save();
        $this->artisan('channels:watch', ['--once' => true])->assertSuccessful();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function withoutScope(callable $read): mixed
    {
        return $this->app->make(TenantContext::class)->withoutScope($read);
    }
}
