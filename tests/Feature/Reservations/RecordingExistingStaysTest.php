<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Reservations\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bookings that already exist can be written down.
 *
 * "Arrival dates in the past cannot be booked" is the right rule for a sale and
 * the wrong one for a record. Anybody moving onto this platform from another
 * tool starts with months of finished stays and guests already in the building,
 * and every one of those has an arrival in the past — so the rule, applied to the
 * only path that creates reservations, made the platform unusable for the thing
 * it is for.
 *
 * The line being drawn is between lead time and inventory. Lead time — not in the
 * past, N hours' notice — answers "may this be sold now", and has nothing to
 * decide about a stay that has already begun. Inventory answers "is the night
 * free", and is what stops a night being sold twice. The flag waives the first
 * and never the second, which is why it needs no permission.
 */
class RecordingExistingStaysTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_past_arrival_is_refused_and_says_how_to_record_it(): void
    {
        $listing = $this->bookableListing();

        $response = $this->postJson('/api/v1/reservations', [
            'listing_id' => $listing,
            'check_in' => now()->subDays(20)->toDateString(),
            'check_out' => now()->subDays(15)->toDateString(),
            'adults' => 2,
            'guest' => ['first_name' => 'Past', 'last_name' => 'Guest'],
        ])->assertStatus(409);

        // A refusal that does not name the way forward is the same dead end as an
        // empty picker: correct, and useless to the person reading it.
        $this->assertStringContainsString('already started', $response->json('message'));
    }

    public function test_a_stay_that_finished_last_month_can_be_recorded(): void
    {
        $listing = $this->bookableListing();

        $this->postJson('/api/v1/reservations', [
            'listing_id' => $listing,
            'check_in' => now()->subDays(20)->toDateString(),
            'check_out' => now()->subDays(15)->toDateString(),
            'adults' => 2,
            'records_existing_stay' => true,
            'booked_at' => now()->subDays(60)->toDateString(),
            'guest' => ['first_name' => 'Past', 'last_name' => 'Guest'],
        ])->assertCreated()
            ->assertJsonPath('data.stay.nights', 5);
    }

    public function test_a_guest_already_in_the_building_can_be_recorded(): void
    {
        $listing = $this->bookableListing();

        // The most urgent case of all: somebody is in the flat right now, and the
        // platform cannot be adopted until it can say so.
        $this->postJson('/api/v1/reservations', [
            'listing_id' => $listing,
            'check_in' => now()->subDays(2)->toDateString(),
            'check_out' => now()->addDays(3)->toDateString(),
            'adults' => 2,
            'records_existing_stay' => true,
            'guest' => ['first_name' => 'In', 'last_name' => 'House'],
        ])->assertCreated();
    }

    public function test_when_it_was_booked_is_kept_rather_than_defaulted_to_today(): void
    {
        $listing = $this->bookableListing();

        $bookedOn = now()->subDays(60)->toDateString();

        $this->postJson('/api/v1/reservations', [
            'listing_id' => $listing,
            'check_in' => now()->subDays(20)->toDateString(),
            'check_out' => now()->subDays(15)->toDateString(),
            'records_existing_stay' => true,
            'booked_at' => $bookedOn,
            'guest' => ['first_name' => 'Past', 'last_name' => 'Guest'],
        ])->assertCreated();

        // Without this every migrated booking looks like it was taken the day it
        // was typed in, and lead time, booking pace and "bookings this week" are
        // all drawn from that column.
        $this->assertDatabaseCount('reservations', 1);
        $this->assertSame(
            $bookedOn,
            Reservation::query()->sole()->booked_at->toDateString(),
        );
    }

    public function test_it_cannot_be_used_to_sell_a_night_twice(): void
    {
        $listing = $this->bookableListing();

        $this->postJson('/api/v1/reservations', [
            'listing_id' => $listing,
            'check_in' => now()->subDays(20)->toDateString(),
            'check_out' => now()->subDays(15)->toDateString(),
            'records_existing_stay' => true,
            'guest' => ['first_name' => 'First', 'last_name' => 'Guest'],
        ])->assertCreated();

        // The whole reason the flag needs no permission: it waives lead time, and
        // the inventory check it does not touch is the one that matters.
        $this->postJson('/api/v1/reservations', [
            'listing_id' => $listing,
            'check_in' => now()->subDays(19)->toDateString(),
            'check_out' => now()->subDays(17)->toDateString(),
            'records_existing_stay' => true,
            'guest' => ['first_name' => 'Second', 'last_name' => 'Guest'],
        ])->assertStatus(409);

        $this->assertDatabaseCount('reservations', 1);
    }

    public function test_the_notice_rule_still_holds_for_a_future_arrival(): void
    {
        $listing = $this->bookableListing();

        $this->patchJson("/api/v1/listings/{$listing}", ['advance_notice_hours' => 72])->assertOk();

        // Ticking the box must not turn into a general "ignore my own rules":
        // tomorrow is not a stay that has already started.
        $response = $this->postJson('/api/v1/reservations', [
            'listing_id' => $listing,
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(4)->toDateString(),
            'records_existing_stay' => true,
            'guest' => ['first_name' => 'Too', 'last_name' => 'Soon'],
        ])->assertStatus(409);

        $this->assertStringContainsString('notice', $response->json('message'));
    }

    /**
     * A listing on an active property, ready to take a booking.
     */
    private function bookableListing(): string
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $properties = $this->app->make(PropertyService::class);

        /** @var Property $property */
        $property = $properties->create([
            'name' => 'Migration Flat',
            'property_type' => 'apartment',
            'address_line_1' => 'Rua C 3',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'max_occupancy' => 4,
            'base_rate' => 10000,
        ]);

        $properties->activate($property);

        return (string) $property->listings()->sole()->getKey();
    }
}
