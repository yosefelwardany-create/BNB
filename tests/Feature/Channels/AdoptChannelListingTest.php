<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelPuller;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Connecting a channel has to leave you with something.
 *
 * The failure this closes was not a crash. A first connection pulled, discovered
 * one listing, mapped it to nothing — because there was nothing to map it to —
 * and stopped. No property, so no bookings could land; no bookings, so no guest
 * names; no property, so the agent had nowhere to live. A demo of that reads as
 * a platform that imports nothing.
 *
 * The data was never missing. The adapter reads the title, type, address, city,
 * country, capacity, bedrooms and currency on every import. All of it went into
 * a metadata column and was thrown away, because the importer deliberately
 * creates nothing.
 *
 * That caution is right for a *mapping*, which decides whose calendar a booking
 * lands on. It is wrong for a property, where a wrong bedroom count is a typo
 * somebody fixes. Applying one rule to both produced the appearance of safety
 * with none of the use.
 */
class AdoptChannelListingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    public function test_a_discovered_listing_becomes_a_property_carrying_what_the_channel_said(): void
    {
        $account = $this->connectedAccount();

        $this->fakeHostex([
            'properties' => [[
                'id' => 'hx-1',
                'title' => 'Light Green Room',
                'property_type' => 'Private room',
                'address' => '88 Harbour Street',
                'city' => 'Toronto',
                'country_code' => 'CA',
                'person_capacity' => 2,
                'bedrooms' => 1,
                'bathrooms' => 1,
                'currency' => 'CAD',
            ]],
        ]);

        $this->app->make(ChannelPuller::class)->pull($account, full: true);

        $mapping = ChannelListing::query()->sole();
        $this->assertNull($mapping->property_id, 'Nothing exists to match, so it arrives unmapped.');

        $this->postJson("/api/v1/channel-listings/{$mapping->getKey()}/adopt")
            ->assertStatus(201)
            ->assertJsonPath('data.property_id', fn (?string $id): bool => $id !== null);

        $property = Property::query()->sole();

        $this->assertSame('Light Green Room', $property->name);
        $this->assertSame('88 Harbour Street', $property->address_line_1);
        $this->assertSame('Toronto', $property->city);
        $this->assertSame('CA', $property->country_code);
        $this->assertSame(2, $property->max_occupancy);
        $this->assertSame(1, $property->bedrooms);
        $this->assertSame('CAD', $property->currency);

        // And the mapping now points at it, which is the whole purpose.
        $this->assertSame($property->getKey(), $mapping->fresh()->property_id);
        $this->assertNotNull($mapping->fresh()->listing_id);
    }

    public function test_the_property_is_live_so_a_booking_can_actually_land_on_it(): void
    {
        $account = $this->connectedAccount();

        $this->fakeHostex(['properties' => [[
            'id' => 'hx-1',
            'title' => 'Light Green Room',
            // Everything activation needs: a complete address, an occupancy and
            // a nightly rate.
            'address' => '88 Harbour Street',
            'city' => 'Toronto',
            'country_code' => 'CA',
            'postal_code' => 'M5J 2G2',
            'person_capacity' => 2,
            'base_price' => '60.00',
            'currency' => 'CAD',
        ]]]);
        $this->app->make(ChannelPuller::class)->pull($account, full: true);

        $this->postJson('/api/v1/channel-listings/'.ChannelListing::query()->sole()->getKey().'/adopt')
            ->assertStatus(201)
            ->assertJsonPath('needs', null);

        /*
         * Not a draft.
         *
         * The listing is already selling on the channel and probably has a guest
         * in it. A draft property cannot take a reservation, so the next pull
         * would find the booking and have nowhere to put it — the same dead end,
         * one layer further in.
         */
        $this->assertSame('active', Property::query()->sole()->status->value);
    }

    public function test_adopting_then_pulling_brings_in_the_booking_and_the_guest(): void
    {
        $account = $this->connectedAccount();

        $listing = [
            'id' => 'hx-1',
            'title' => 'Light Green Room',
            'city' => 'Toronto',
            'country_code' => 'CA',
            'currency' => 'CAD',
        ];

        $booking = [
            'reservation_code' => 'HMABC123',
            'property_id' => 'hx-1',
            'check_in_date' => '2026-09-03',
            'check_out_date' => '2026-10-07',
            'status' => 'accepted',
            'guest' => ['name' => 'Malik Elkhateeb', 'email' => 'malik@example.test'],
            'financials' => ['total_amount' => '1374.30', 'currency' => 'CAD'],
        ];

        // First pull: the listing is discovered and the booking has nowhere to
        // land, which is exactly the state that made the demo look empty.
        $this->fakeHostex(['properties' => [$listing], 'reservations' => [$booking]]);
        $this->app->make(ChannelPuller::class)->pull($account, full: true);

        $this->assertSame(0, Reservation::query()->count());

        $this->postJson('/api/v1/channel-listings/'.ChannelListing::query()->sole()->getKey().'/adopt')
            ->assertStatus(201);

        // Second pull: now there is somewhere for it to go.
        $this->fakeHostex(['properties' => [$listing], 'reservations' => [$booking]]);
        $this->app->make(ChannelPuller::class)->pull($account, full: true);

        $reservation = Reservation::query()->sole();

        $this->assertSame('HMABC123', $reservation->external_reservation_id);
        $this->assertSame(Property::query()->sole()->getKey(), $reservation->property_id);

        // The guest's name was in the payload all along. It only had nowhere to
        // be recorded against.
        $this->assertSame('Malik', $reservation->guest?->first_name);
        $this->assertSame('Elkhateeb', $reservation->guest?->last_name);
    }

    public function test_a_type_the_platform_does_not_have_is_left_alone_rather_than_guessed(): void
    {
        $account = $this->connectedAccount();

        $this->fakeHostex([
            'properties' => [[
                'id' => 'hx-1',
                'title' => 'Light Green Room',
                // A room in a shared place. There is no "room" type here, and
                // calling it an apartment would be a guess that reaches owner
                // statements and occupancy figures.
                'property_type' => 'Private room',
            ]],
        ]);

        $this->app->make(ChannelPuller::class)->pull($account, full: true);

        $this->postJson('/api/v1/channel-listings/'.ChannelListing::query()->sole()->getKey().'/adopt')
            ->assertStatus(201);

        $this->assertSame('other', Property::query()->sole()->property_type?->value);
    }

    public function test_a_listing_already_attached_to_a_property_is_refused(): void
    {
        $account = $this->connectedAccount();

        $this->fakeHostex(['properties' => [['id' => 'hx-1', 'title' => 'Light Green Room']]]);
        $this->app->make(ChannelPuller::class)->pull($account, full: true);

        $mapping = ChannelListing::query()->sole();

        $this->postJson("/api/v1/channel-listings/{$mapping->getKey()}/adopt")->assertStatus(201);

        // Twice would quietly create a second property for the same listing and
        // split its bookings across both.
        $this->postJson("/api/v1/channel-listings/{$mapping->getKey()}/adopt")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'already attached'));

        $this->assertSame(1, Property::query()->count());
    }

    public function test_what_the_channel_did_not_say_is_left_empty_rather_than_invented(): void
    {
        $account = $this->connectedAccount();

        // A channel that returns almost nothing. The property is still created,
        // because a name and a mapping are worth having.
        $this->fakeHostex(['properties' => [['id' => 'hx-1', 'title' => 'Light Green Room']]]);
        $this->app->make(ChannelPuller::class)->pull($account, full: true);

        $response = $this->postJson('/api/v1/channel-listings/'.ChannelListing::query()->sole()->getKey().'/adopt');
        $response->assertStatus(201, (string) json_encode($response->json()));

        $property = Property::query()->sole();

        $this->assertSame('Light Green Room', $property->name);
        // Missing stays missing. A plausible address reads as fact on an owner
        // statement, and nobody would know to check it.
        $this->assertNull($property->address_line_1);
        // `property_type` cannot be null in the schema, so the honest value is
        // `other` — unspecified — rather than a plausible-looking guess.
        $this->assertSame('other', $property->property_type?->value);
        // Except the two the platform cannot work without, which come from the
        // organization rather than from a guess.
        $this->assertSame($this->organization->base_currency, $property->currency);
        $this->assertNotNull($property->timezone);
    }

    // ---------------------------------------------------------------- fixtures

    private function connectedAccount(): ChannelAccount
    {
        $this->organization = $this->createOrganization(['base_currency' => 'CAD']);
        $this->user = $this->createUser($this->organization);
        $this->actingAsUser($this->user, $this->organization);

        return ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'hostex',
            'name' => 'Hostex',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'credentials' => ['access_token' => 'token-for-tests'],
            'import_reservations' => true,
        ]);
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $data
     */
    private function fakeHostex(array $data): void
    {
        Http::fake(function ($request) use ($data) {
            $url = (string) $request->url();

            foreach (['properties', 'reservations', 'conversations'] as $collection) {
                if (str_contains($url, '/'.$collection)) {
                    // Hostex mirrors the HTTP status into the body, so a
                    // successful call carries error_code 200.
                    return Http::response([
                        'error_code' => 200,
                        'error_msg' => 'Done',
                        'data' => [$collection => $data[$collection] ?? []],
                    ], 200);
                }
            }

            return Http::response(['error_code' => 200, 'data' => []], 200);
        });
    }
}
