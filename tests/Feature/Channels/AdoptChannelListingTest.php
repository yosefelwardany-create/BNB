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
use Illuminate\Http\Client\Factory;
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
                'address' => '88 Harbour Street',
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
        $this->assertNull($property->country_code);
        $this->assertSame(0, $property->max_occupancy);
        $this->assertSame(0, $property->bedrooms);
        $this->assertSame('CAD', $property->currency);

        // And the mapping now points at it, which is the whole purpose.
        $this->assertSame($property->getKey(), $mapping->fresh()->property_id);
        $this->assertNotNull($mapping->fresh()->listing_id);
    }

    public function test_missing_capacity_keeps_an_adopted_property_draft_without_preventing_inbound_bookings(): void
    {
        $account = $this->connectedAccount();

        $this->fakeHostex(['properties' => [[
            'id' => 'hx-1',
            'title' => 'Light Green Room',
            // The documented property endpoint does not provide capacity.
            'address' => '88 Harbour Street',
        ]]]);
        $this->app->make(ChannelPuller::class)->pull($account, full: true);

        $this->postJson('/api/v1/channel-listings/'.ChannelListing::query()->sole()->getKey().'/adopt')
            ->assertStatus(201)
            ->assertJsonPath('needs', fn ($needs) => is_string($needs) && str_contains($needs, 'occupancy'));

        // Keep capacity unknown and require local completion before activation.
        $this->assertSame('draft', Property::query()->sole()->status->value);
    }

    public function test_adopting_then_pulling_brings_in_the_booking_and_the_guest(): void
    {
        $account = $this->connectedAccount();

        $listing = [
            'id' => 'hx-1',
            'title' => 'Light Green Room',
        ];

        $booking = [
            'reservation_code' => 'HMABC123',
            'property_id' => 'hx-1',
            'check_in_date' => '2026-09-03',
            'check_out_date' => '2026-10-07',
            'status' => 'accepted',
            'guest_name' => 'Malik Elkhateeb', 'guest_email' => 'malik@example.test',
            'rates' => ['rate' => ['amount' => '1374.30', 'currency' => 'CAD']],
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
        Http::swap(new Factory);
        foreach ($data['properties'] ?? [] as $index => $property) {
            $data['properties'][$index]['channels'] = [['channel_type' => 'airbnb', 'listing_id' => 'ota-'.$property['id'], 'currency' => 'CAD']];
            $data['listings'][] = ['channel_type' => 'airbnb', 'listing_id' => 'ota-'.$property['id'], 'title' => $property['title'], 'metadata' => ['city' => 'Toronto']];
        }
        Http::fake(function ($request) use ($data) {
            $url = (string) $request->url();

            if (str_contains($url, '/listings/calendar')) {
                return Http::response(['data' => ['listings' => array_map(fn ($listing) => $listing + ['calendar' => []], $request['listings'])]]);
            }
            if (str_contains($url, '/listings/airbnb/price_and_rules')) {
                return Http::response(['data' => ['listing_currency' => 'CAD', 'base_price' => 60, 'max_guests' => 2]]);
            }
            foreach (['properties', 'reservations', 'conversations', 'listings', 'transactions'] as $collection) {
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
