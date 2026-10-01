<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Domain\Listings\Models\Listing;
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

    public function test_a_refusal_names_the_booking_in_the_way(): void
    {
        $listing = $this->bookableListing();

        $this->postJson('/api/v1/reservations', [
            'listing_id' => $listing,
            'check_in' => now()->addDays(30)->toDateString(),
            'check_out' => now()->addDays(34)->toDateString(),
            'guest' => ['first_name' => 'First'],
        ])->assertCreated();

        $message = $this->postJson('/api/v1/reservations', [
            'listing_id' => $listing,
            'check_in' => now()->addDays(32)->toDateString(),
            'check_out' => now()->addDays(36)->toDateString(),
            'guest' => ['first_name' => 'Second'],
        ])->assertStatus(409)->json('message');

        // "already booked or blocked" said neither which nor what to do about it,
        // which is how a correctly refused clash gets reported as a bug.
        $this->assertStringContainsString('HB-', $message);
        $this->assertStringNotContainsString('booked or blocked', $message);
    }

    public function test_a_refusal_names_the_block_in_the_way(): void
    {
        $listing = $this->bookableListing();
        $property = Listing::query()->findOrFail($listing)->property_id;

        $this->postJson('/api/v1/calendar/blocks', [
            'property_id' => $property,
            'kind' => 'maintenance',
            'start_date' => now()->addDays(60)->toDateString(),
            'end_date' => now()->addDays(65)->toDateString(),
            'title' => 'Boiler replaced',
        ])->assertCreated();

        $message = $this->postJson('/api/v1/reservations', [
            'listing_id' => $listing,
            'check_in' => now()->addDays(61)->toDateString(),
            'check_out' => now()->addDays(63)->toDateString(),
            'guest' => ['first_name' => 'Blocked'],
        ])->assertStatus(409)->json('message');

        $this->assertStringContainsString('Boiler replaced', $message);
        $this->assertStringContainsString('Unblock', $message);
    }

    public function test_a_cancelled_booking_can_be_recorded_over_a_live_one(): void
    {
        $listing = $this->bookableListing();

        $this->postJson('/api/v1/reservations', [
            'listing_id' => $listing,
            'check_in' => now()->addDays(30)->toDateString(),
            'check_out' => now()->addDays(34)->toDateString(),
            'guest' => ['first_name' => 'Replacement'],
        ])->assertCreated();

        /*
         * The migration case this exists for: the guest cancelled, somebody else
         * took the dates, and both belong on the record. A cancelled booking holds
         * no nights, so it does not clash — and the channel's own code comes with
         * it, which is what a payout query is settled with.
         */
        $this->postJson('/api/v1/reservations', [
            'listing_id' => $listing,
            'check_in' => now()->addDays(30)->toDateString(),
            'check_out' => now()->addDays(34)->toDateString(),
            'status' => 'cancelled',
            'external_confirmation_code' => 'HMX5F8DWAE',
            'guest' => ['first_name' => 'Cancelled'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.external_confirmation_code', 'HMX5F8DWAE');

        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_a_booking_is_findable_by_the_channels_own_code(): void
    {
        $listing = $this->bookableListing();

        $this->postJson('/api/v1/reservations', [
            'listing_id' => $listing,
            'check_in' => now()->addDays(30)->toDateString(),
            'check_out' => now()->addDays(34)->toDateString(),
            'external_confirmation_code' => 'HMX5F8DWAE',
            'guest' => ['first_name' => 'Airbnb'],
        ])->assertCreated();

        // Search already covered this column; nothing could put a value in it.
        $this->getJson('/api/v1/reservations?search=HMX5F8DWAE')
            ->assertOk()
            ->assertJsonCount(1, 'data');
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
