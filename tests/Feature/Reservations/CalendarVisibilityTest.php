<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Domain\Listings\Services\ListingService;
use App\Domain\Properties\Services\PropertyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A booking that exists appears on the calendar.
 *
 * It did not, and the reason is the most dangerous kind of bug this platform can
 * have: two parts of it disagreed about what counts as inventory. The booking
 * engine sells a draft listing quite happily — a stay taken by hand is legitimate
 * before anything is on sale — and the calendar asked only for published and
 * paused listings. So a property added through the interface, whose listing is a
 * draft, had a paid booking against it and no row on the calendar at all.
 *
 * The consequence is not a missing feature. Somebody reading that calendar sees
 * those nights as free and sells them again. The engine would refuse the clash,
 * because availability is computed rather than read off the screen — but only
 * after the dates have been promised to a second guest.
 *
 * So the rule is: if a night can be sold, the calendar shows it. Anything else is
 * a screen that cannot be trusted, and a calendar nobody trusts is worthless.
 */
class CalendarVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_booking_on_a_draft_listing_is_on_the_calendar(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $property = $this->postJson('/api/v1/properties', [
            'name' => 'Alfama Terrace',
            'property_type' => 'apartment',
            'address_line_1' => 'Rua dos Remédios 12',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'max_occupancy' => 4,
            'base_rate' => 12000,
        ])->assertCreated()->json('data.id');

        $listing = $this->getJson('/api/v1/listings')->assertOk()->json('data.0');

        // The state a property is in for as long as it takes somebody to get
        // round to publishing, which may be never if they only take direct
        // bookings.
        $this->assertSame('draft', $listing['status']);

        $this->postJson("/api/v1/properties/{$property}/activate")->assertOk();

        $checkIn = now()->addWeek()->toDateString();
        $checkOut = now()->addWeek()->addDays(3)->toDateString();

        $reservation = $this->postJson('/api/v1/reservations', [
            'listing_id' => $listing['id'],
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => 2,
            'guest' => ['first_name' => 'Ana', 'last_name' => 'Silva'],
        ])->assertCreated()->json('data');

        $calendar = $this->getJson(sprintf(
            '/api/v1/calendar?from=%s&to=%s',
            now()->addDays(5)->toDateString(),
            now()->addDays(14)->toDateString(),
        ))->assertOk()->json();

        $this->assertCount(1, $calendar['listings'], 'The draft listing needs a row.');

        $row = $calendar['listings'][0];
        $this->assertSame($listing['id'], $row['listing_id']);

        // Reported so the row can say it is not on sale. A grid that looked the
        // same either way would tell somebody their flat was live when it is not.
        $this->assertSame('draft', $row['listing_status']);

        $sold = array_values(array_filter($row['days'], fn (array $day): bool => $day['sold_units'] > 0));

        $this->assertCount(3, $sold, 'Three nights were sold, so three cells are sold.');
        $this->assertSame($checkIn, $sold[0]['date']);
        $this->assertContains($reservation['id'], $sold[0]['reservation_ids']);

        // And the booking itself travels with the grid, so a cell can carry the
        // guest's name rather than only the fact that it is taken.
        $this->assertCount(1, $calendar['reservations']);
        $this->assertSame('Ana Silva', $calendar['reservations'][0]['guest_name']);
        $this->assertSame($listing['id'], $calendar['reservations'][0]['listing_id']);
    }

    public function test_blocked_dates_can_be_unblocked_again(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $properties = $this->app->make(PropertyService::class);
        $property = $properties->activate($properties->create([
            'name' => 'Blocked Flat',
            'property_type' => 'apartment',
            'address_line_1' => 'Rua D 4',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'max_occupancy' => 2,
            'base_rate' => 9000,
        ]));

        $listing = $property->listings()->sole();

        $from = now()->addDays(40)->toDateString();
        $to = now()->addDays(45)->toDateString();

        $this->postJson('/api/v1/calendar/blocks', [
            'property_id' => $property->getKey(),
            'kind' => 'maintenance',
            'start_date' => $from,
            'end_date' => $to,
            'title' => 'Boiler',
        ])->assertCreated();

        $booking = [
            'listing_id' => $listing->getKey(),
            'check_in' => now()->addDays(41)->toDateString(),
            'check_out' => now()->addDays(43)->toDateString(),
            'guest' => ['first_name' => 'Wants', 'last_name' => 'Those'],
        ];

        $this->postJson('/api/v1/reservations', $booking)->assertStatus(409);

        /*
         * The calendar has to carry the block itself, not only shade the cells.
         * A shut cell does not say which block shut it, and "already booked or
         * blocked" is all a person sees when they try to sell across one — so
         * without this the only cure for a block put in by mistake was to stop
         * using those dates.
         */
        $blocks = $this->getJson("/api/v1/calendar?from={$from}&to={$to}")
            ->assertOk()
            ->json('blocks');

        $this->assertCount(1, $blocks);
        $this->assertSame('Boiler', $blocks[0]['label']);

        $this->deleteJson("/api/v1/calendar/blocks/{$blocks[0]['id']}")
            ->assertOk()
            ->assertJson(['message' => 'Block removed.']);

        // And the dates are genuinely sellable again, which is the point.
        $this->postJson('/api/v1/reservations', $booking)->assertCreated();
    }

    public function test_a_paused_listing_is_still_on_the_calendar(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $this->app->make(PropertyService::class)
            ->create(['name' => 'Baixa Riverside', 'property_type' => 'apartment']);

        $this->assertCount(1, $this->getJson('/api/v1/calendar?from='.now()->toDateString()
            .'&to='.now()->addDays(7)->toDateString())->assertOk()->json('listings'));
    }

    public function test_an_archived_listing_is_not_on_the_calendar(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $property = $this->app->make(PropertyService::class)
            ->create(['name' => 'Retired Flat', 'property_type' => 'apartment']);

        // Retired inventory. Archiving is how a listing leaves — it is never
        // deleted, because its reservations and ledger entries have to stay
        // resolvable — but a row for everything anybody ever had would bury the
        // ones in use.
        $this->app->make(ListingService::class)
            ->archive($property->listings()->sole(), 'No longer let');

        $this->assertCount(0, $this->getJson('/api/v1/calendar?from='.now()->toDateString()
            .'&to='.now()->addDays(7)->toDateString())->assertOk()->json('listings'));
    }
}
