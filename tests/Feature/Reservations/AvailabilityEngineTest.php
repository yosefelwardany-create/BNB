<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Domain\Availability\DataObjects\AvailabilityRequest;
use App\Domain\Availability\Models\CalendarBlock;
use App\Domain\Availability\Models\CalendarDay;
use App\Domain\Availability\Services\AvailabilityEngine;
use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\Unit;
use App\Domain\Properties\Models\UnitType;
use App\Domain\Reservations\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The availability engine is the one thing in the product that absolutely must
 * not be wrong: a double booking means two guests at one door.
 */
class AvailabilityEngineTest extends TestCase
{
    use RefreshDatabase;

    private AvailabilityEngine $engine;

    private Property $property;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = $this->app->make(AvailabilityEngine::class);

        $organization = $this->createOrganization();

        $this->property = Property::factory()->active()->create([
            'organization_id' => $organization->getKey(),
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => $this->property->currency,
            'minimum_nights' => 1,
        ]);
    }

    public function test_free_dates_are_available(): void
    {
        $result = $this->engine->check($this->request($this->day(61), $this->day(65)));

        $this->assertTrue($result->isAvailable, $result->reasonSummary());
    }

    public function test_a_confirmed_reservation_blocks_overlapping_dates(): void
    {
        $this->reservationFor($this->day(61), $this->day(65), 'confirmed');

        $overlapping = [
            [$this->day(61), $this->day(65)],   // exactly the same
            [$this->day(62), $this->day(64)],   // inside
            [$this->day(59), $this->day(63)],   // overlaps the start
            [$this->day(63), $this->day(68)],   // overlaps the end
            [$this->day(57), $this->day(70)],   // encloses it
        ];

        foreach ($overlapping as [$from, $to]) {
            $result = $this->engine->check($this->request($from, $to));

            $this->assertFalse(
                $result->isAvailable,
                sprintf('%s → %s should conflict with the existing booking.', $from, $to),
            );
        }
    }

    public function test_a_same_day_turnover_is_not_a_conflict(): void
    {
        // A stay ending on the 5th and one starting on the 5th do not overlap:
        // the first guest leaves in the morning, the second arrives later.
        $this->reservationFor($this->day(61), $this->day(65), 'confirmed');

        $this->assertTrue(
            $this->engine->check($this->request($this->day(65), $this->day(68)))->isAvailable,
        );

        $this->assertTrue(
            $this->engine->check($this->request($this->day(57), $this->day(61)))->isAvailable,
        );
    }

    public function test_a_cancelled_reservation_releases_its_dates(): void
    {
        $reservation = $this->reservationFor($this->day(61), $this->day(65), 'confirmed');

        $this->assertFalse($this->engine->check($this->request($this->day(61), $this->day(65)))->isAvailable);

        $reservation->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

        $this->assertTrue($this->engine->check($this->request($this->day(61), $this->day(65)))->isAvailable);
    }

    public function test_an_inquiry_does_not_hold_inventory(): void
    {
        // Enquiries and quotes must not take nights off the market.
        $this->reservationFor($this->day(61), $this->day(65), 'inquiry');

        $this->assertTrue($this->engine->check($this->request($this->day(61), $this->day(65)))->isAvailable);
    }

    public function test_a_tentative_hold_does_block_inventory(): void
    {
        $this->reservationFor($this->day(61), $this->day(65), 'tentative');

        $this->assertFalse($this->engine->check($this->request($this->day(62), $this->day(64)))->isAvailable);
    }

    public function test_a_calendar_block_makes_dates_unavailable(): void
    {
        CalendarBlock::query()->create([
            'organization_id' => $this->property->organization_id,
            'property_id' => $this->property->getKey(),
            'kind' => CalendarBlock::KIND_OWNER_STAY,
            'start_date' => $this->day(100),
            'end_date' => $this->day(105),
            'title' => 'Owner staying',
        ]);

        $this->assertFalse($this->engine->check($this->request($this->day(102), $this->day(104)))->isAvailable);
        $this->assertTrue($this->engine->check($this->request($this->day(105), $this->day(108)))->isAvailable);
    }

    public function test_modifying_a_reservation_does_not_conflict_with_itself(): void
    {
        $reservation = $this->reservationFor($this->day(61), $this->day(65), 'confirmed');

        $request = new AvailabilityRequest(
            property: $this->property,
            checkIn: CarbonImmutable::parse($this->day(62)),
            checkOut: CarbonImmutable::parse($this->day(66)),
            listing: $this->listing,
            ignoreReservationId: $reservation->getKey(),
        );

        $this->assertTrue($this->engine->check($request)->isAvailable);
    }

    public function test_a_multi_unit_property_sells_until_its_units_run_out(): void
    {
        $property = Property::factory()->multiUnit()->active()->create([
            'organization_id' => $this->property->organization_id,
        ]);

        $units = collect(range(1, 3))->map(fn (int $i): Unit => Unit::factory()->create([
            'organization_id' => $property->organization_id,
            'property_id' => $property->getKey(),
            'name' => 'Studio '.$i,
            'code' => (string) (100 + $i),
        ]));

        $listing = Listing::factory()->published()->create([
            'organization_id' => $property->organization_id,
            'property_id' => $property->getKey(),
            'currency' => $property->currency,
        ]);

        $request = fn (): AvailabilityRequest => new AvailabilityRequest(
            property: $property,
            checkIn: CarbonImmutable::parse($this->day(130)),
            checkOut: CarbonImmutable::parse($this->day(134)),
            listing: $listing,
        );

        $this->assertSame(3, $this->engine->check($request())->availableUnits);

        // Sell two of the three.
        foreach ($units->take(2) as $unit) {
            $this->reservationFor($this->day(130), $this->day(134), 'confirmed', $property, $unit);
        }

        $this->assertSame(1, $this->engine->check($request())->availableUnits);

        // Sell the last one.
        $this->reservationFor($this->day(130), $this->day(134), 'confirmed', $property, $units->last());

        $result = $this->engine->check($request());
        $this->assertFalse($result->isAvailable);
        $this->assertSame(0, $result->availableUnits);
    }

    public function test_booking_a_parent_unit_makes_its_child_rooms_unavailable(): void
    {
        // A two-bedroom flat also offered as two lockable rooms: selling the
        // whole flat must take both rooms off the market.
        $property = Property::factory()->multiUnit()->active()->create([
            'organization_id' => $this->property->organization_id,
        ]);

        $flat = Unit::factory()->create([
            'organization_id' => $property->organization_id,
            'property_id' => $property->getKey(),
            'name' => 'Whole flat',
            'code' => 'FLAT',
        ]);

        $roomA = Unit::factory()->create([
            'organization_id' => $property->organization_id,
            'property_id' => $property->getKey(),
            'parent_unit_id' => $flat->getKey(),
            'name' => 'Room A',
            'code' => 'A',
        ]);

        $this->reservationFor($this->day(160), $this->day(163), 'confirmed', $property, $flat);

        $available = $this->engine->availableUnitIds(
            $property,
            CarbonImmutable::parse($this->day(160)),
            CarbonImmutable::parse($this->day(163)),
        );

        $this->assertNotContains($roomA->getKey(), $available, 'A room inside a booked flat must not be sellable.');
        $this->assertNotContains($flat->getKey(), $available);
    }

    public function test_booking_a_child_room_makes_the_whole_flat_unavailable(): void
    {
        $property = Property::factory()->multiUnit()->active()->create([
            'organization_id' => $this->property->organization_id,
        ]);

        $flat = Unit::factory()->create([
            'organization_id' => $property->organization_id,
            'property_id' => $property->getKey(),
            'name' => 'Whole flat',
            'code' => 'FLAT2',
        ]);

        $roomA = Unit::factory()->create([
            'organization_id' => $property->organization_id,
            'property_id' => $property->getKey(),
            'parent_unit_id' => $flat->getKey(),
            'name' => 'Room A',
            'code' => 'A2',
        ]);

        $this->reservationFor($this->day(160), $this->day(163), 'confirmed', $property, $roomA);

        $available = $this->engine->availableUnitIds(
            $property,
            CarbonImmutable::parse($this->day(160)),
            CarbonImmutable::parse($this->day(163)),
        );

        $this->assertNotContains($flat->getKey(), $available, 'The whole flat cannot be sold while one of its rooms is let.');
    }

    public function test_an_out_of_service_unit_is_not_sellable(): void
    {
        $property = Property::factory()->multiUnit()->active()->create([
            'organization_id' => $this->property->organization_id,
        ]);

        Unit::factory()->outOfService()->create([
            'organization_id' => $property->organization_id,
            'property_id' => $property->getKey(),
            'code' => 'OOS',
        ]);

        $this->assertSame([], $this->engine->availableUnitIds(
            $property,
            CarbonImmutable::parse($this->day(190)),
            CarbonImmutable::parse($this->day(192)),
        ));
    }

    public function test_minimum_stay_is_enforced_from_the_arrival_date(): void
    {
        $this->listing->forceFill(['minimum_nights' => 3])->save();

        $tooShort = $this->engine->check($this->request($this->day(61), $this->day(63)));

        $this->assertFalse($tooShort->isAvailable);
        $this->assertStringContainsString('minimum stay', $tooShort->reasonSummary());

        $this->assertTrue($this->engine->check($this->request($this->day(61), $this->day(64)))->isAvailable);
    }

    public function test_a_per_date_minimum_stay_overrides_the_listing_default(): void
    {
        $this->listing->forceFill(['minimum_nights' => 1])->save();

        CalendarDay::query()->create([
            'organization_id' => $this->property->organization_id,
            'listing_id' => $this->listing->getKey(),
            'calendar_date' => $this->day(240),
            'minimum_nights' => 5,
        ]);

        $result = $this->engine->check($this->request($this->day(240), $this->day(242)));

        $this->assertFalse($result->isAvailable);
        $this->assertStringContainsString('minimum stay of 5', $result->reasonSummary());
    }

    public function test_closed_to_arrival_prevents_starting_a_stay_on_that_date(): void
    {
        CalendarDay::query()->create([
            'organization_id' => $this->property->organization_id,
            'listing_id' => $this->listing->getKey(),
            'calendar_date' => $this->day(73),
            'closed_to_arrival' => true,
        ]);

        $this->assertFalse($this->engine->check($this->request($this->day(73), $this->day(76)))->isAvailable);

        // Passing through the date mid-stay is fine.
        $this->assertTrue($this->engine->check($this->request($this->day(72), $this->day(76)))->isAvailable);
    }

    public function test_occupancy_limits_are_enforced(): void
    {
        $this->listing->forceFill(['max_occupancy' => 4])->save();

        $request = new AvailabilityRequest(
            property: $this->property,
            checkIn: CarbonImmutable::parse($this->day(61)),
            checkOut: CarbonImmutable::parse($this->day(64)),
            listing: $this->listing,
            guests: 6,
        );

        $result = $this->engine->check($request);

        $this->assertFalse($result->isAvailable);
        $this->assertStringContainsString('accommodates 4', $result->reasonSummary());
    }

    public function test_an_inactive_property_cannot_be_booked(): void
    {
        $this->property->forceFill(['status' => 'inactive'])->save();

        $result = $this->engine->check($this->request($this->day(61), $this->day(64)));

        $this->assertFalse($result->isAvailable);
        $this->assertStringContainsString('inactive', $result->reasonSummary());
    }

    public function test_the_calendar_reports_per_day_occupancy(): void
    {
        $this->reservationFor($this->day(70), $this->day(73), 'confirmed');

        $days = $this->engine->calendar(
            $this->listing,
            CarbonImmutable::parse($this->day(69)),
            CarbonImmutable::parse($this->day(75)),
        );

        $byDate = collect($days)->keyBy('date');

        $this->assertTrue($byDate[$this->day(69)]->isAvailable);
        $this->assertFalse($byDate[$this->day(70)]->isAvailable);
        $this->assertFalse($byDate[$this->day(72)]->isAvailable);
        // The checkout date is free again.
        $this->assertTrue($byDate[$this->day(73)]->isAvailable);
        $this->assertSame(1, $byDate[$this->day(70)]->soldUnits);
    }

    public function test_reserve_raises_when_the_dates_go_during_the_check(): void
    {
        $this->reservationFor($this->day(61), $this->day(65), 'confirmed');

        $this->expectException(\App\Domain\Availability\Exceptions\DatesUnavailableException::class);

        $this->engine->reserve(
            $this->request($this->day(62), $this->day(64)),
            fn () => 'should never run',
        );
    }

    // ------------------------------------------------------------------

    private function request(string $from, string $to): AvailabilityRequest
    {
        return new AvailabilityRequest(
            property: $this->property,
            checkIn: CarbonImmutable::parse($from),
            checkOut: CarbonImmutable::parse($to),
            listing: $this->listing,
        );
    }

    /**
     * A date N days from today.
     *
     * Tests use relative dates so the suite does not start failing the moment
     * a hard-coded year slips into the past — and because booking rules
     * legitimately refuse arrivals in the past.
     */
    private function day(int $offset): string
    {
        return CarbonImmutable::now($this->property->timezone)->addDays($offset)->toDateString();
    }

    private function reservationFor(
        string $from,
        string $to,
        string $status,
        ?Property $property = null,
        ?Unit $unit = null,
    ): Reservation {
        $property ??= $this->property;
        $checkIn = CarbonImmutable::parse($from);
        $checkOut = CarbonImmutable::parse($to);

        return Reservation::query()->create([
            'organization_id' => $property->organization_id,
            'property_id' => $property->getKey(),
            'unit_id' => $unit?->getKey(),
            'confirmation_code' => 'T-'.Str::upper(Str::random(8)),
            'status' => $status,
            'source' => 'direct',
            'check_in_date' => $checkIn,
            'check_out_date' => $checkOut,
            'nights' => (int) $checkIn->diffInDays($checkOut),
            'currency' => $property->currency,
        ]);
    }
}
