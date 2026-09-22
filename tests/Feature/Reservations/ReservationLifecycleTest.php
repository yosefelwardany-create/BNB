<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Domain\Availability\Exceptions\DatesUnavailableException;
use App\Domain\Guests\Models\Guest;
use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\CancellationPolicy;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Events\ReservationCancelled;
use App\Domain\Reservations\Events\ReservationConfirmed;
use App\Domain\Reservations\Exceptions\InvalidReservationTransitionException;
use App\Domain\Reservations\Models\ReservationCharge;
use App\Domain\Reservations\Services\ReservationService;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReservationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private ReservationService $service;

    private Property $property;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(ReservationService::class);

        $organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'cleaning_fee' => 5000,
            'max_occupancy' => 6,
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);
    }

    public function test_creating_a_reservation_prices_it_and_records_every_night(): void
    {
        $reservation = $this->service->create($this->request(3));

        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(3, (int) $reservation->nights);
        $this->assertSame(30000, (int) $reservation->accommodation_total);
        $this->assertSame(5000, (int) $reservation->fees_total);
        $this->assertSame(35000, (int) $reservation->grand_total);
        $this->assertSame(35000, (int) $reservation->balance_due);

        // One row per night, each carrying how its rate was reached.
        $this->assertSame(3, $reservation->stayNights()->count());
        $this->assertNotEmpty($reservation->stayNights()->first()->pricing_trace);
    }

    public function test_a_confirmation_code_is_generated_and_unique(): void
    {
        $first = $this->service->create($this->request(2, 20));
        $second = $this->service->create($this->request(2, 40));

        $this->assertNotEmpty($first->confirmation_code);
        $this->assertNotSame($first->confirmation_code, $second->confirmation_code);
        $this->assertStringStartsWith('HB-', $first->confirmation_code);
    }

    public function test_a_guest_profile_is_created_and_reused(): void
    {
        $first = $this->service->create($this->request(2, 20, [
            'first_name' => 'Marta',
            'last_name' => 'Silva',
            'email' => 'Marta.Silva@Example.com ',
        ]));

        // The same person booking again, with the address written differently.
        $second = $this->service->create($this->request(2, 40, [
            'first_name' => 'Marta',
            'email' => 'marta.silva@example.com',
        ]));

        $this->assertSame($first->guest_id, $second->guest_id);
        $this->assertSame(1, Guest::query()->count());

        $guest = Guest::query()->findOrFail($first->guest_id);
        $this->assertSame(2, (int) $guest->reservations_count);
        $this->assertSame(4, (int) $guest->nights_count);
    }

    public function test_a_second_booking_for_the_same_dates_is_refused(): void
    {
        $this->service->create($this->request(3, 30));

        $this->expectException(DatesUnavailableException::class);

        $this->service->create($this->request(3, 30));
    }

    public function test_an_inquiry_does_not_block_a_later_confirmed_booking(): void
    {
        $inquiry = $this->service->create($this->request(3, 30, null, ReservationStatus::Inquiry));

        $this->assertSame(ReservationStatus::Inquiry, $inquiry->status);

        // Someone else books the same dates; the enquiry held nothing.
        $confirmed = $this->service->create($this->request(3, 30));

        $this->assertSame(ReservationStatus::Confirmed, $confirmed->status);
    }

    public function test_confirming_an_inquiry_re_checks_availability(): void
    {
        $inquiry = $this->service->create($this->request(3, 30, null, ReservationStatus::Inquiry));

        // The dates are sold while the enquiry sits unanswered.
        $this->service->create($this->request(3, 30));

        $this->expectException(DatesUnavailableException::class);

        $this->service->confirm($inquiry);
    }

    public function test_status_transitions_follow_the_lifecycle(): void
    {
        $reservation = $this->service->create($this->request(2, 30));

        $this->service->checkIn($reservation);
        $this->assertSame(ReservationStatus::CheckedIn, $reservation->refresh()->status);
        $this->assertNotNull($reservation->checked_in_at);

        $this->service->checkOut($reservation);
        $this->assertSame(ReservationStatus::CheckedOut, $reservation->refresh()->status);

        // Every transition is recorded.
        $this->assertSame(3, $reservation->statusChanges()->count());
    }

    public function test_an_illegal_transition_is_refused(): void
    {
        $reservation = $this->service->create($this->request(2, 30));

        $this->service->checkIn($reservation);
        $this->service->checkOut($reservation);

        // A departed guest cannot check in again.
        $this->expectException(InvalidReservationTransitionException::class);

        $this->service->checkIn($reservation->refresh());
    }

    public function test_modifying_dates_reprices_the_stay(): void
    {
        $reservation = $this->service->create($this->request(3, 30));

        $this->assertSame(30000, (int) $reservation->accommodation_total);

        $this->service->modify($reservation, [
            'check_in' => $this->futureDate(30),
            'check_out' => $this->futureDate(35),
        ], 'Guest extended their stay');

        $reservation->refresh();

        $this->assertSame(5, (int) $reservation->nights);
        $this->assertSame(50000, (int) $reservation->accommodation_total);
        $this->assertSame(55000, (int) $reservation->grand_total);
        $this->assertSame(5, $reservation->stayNights()->count());
    }

    public function test_modifying_into_occupied_dates_is_refused(): void
    {
        $first = $this->service->create($this->request(2, 30));
        $this->service->create($this->request(2, 40));

        $this->expectException(DatesUnavailableException::class);

        $this->service->modify($first, [
            'check_in' => $this->futureDate(40),
            'check_out' => $this->futureDate(42),
        ]);
    }

    public function test_cancelling_releases_the_dates_and_computes_a_refund(): void
    {
        Event::fake([ReservationCancelled::class, ReservationConfirmed::class]);

        $policy = CancellationPolicy::query()->where('slug', 'moderate')->firstOrFail();

        $this->listing->forceFill(['cancellation_policy_id' => $policy->getKey()])->save();

        $reservation = $this->service->create($this->request(3, 30));

        // Pretend the guest has paid in full.
        $reservation->forceFill(['paid_total' => $reservation->grand_total])->save();

        $this->service->cancel($reservation, 'Change of plans', 'guest');

        $reservation->refresh();

        $this->assertSame(ReservationStatus::Cancelled, $reservation->status);
        $this->assertNotNull($reservation->cancelled_at);
        $this->assertSame('guest', $reservation->cancelled_by);

        // 30 days out is inside the Moderate policy's 5-day free window.
        $this->assertSame(35000, (int) $reservation->cancellation_refund);

        Event::assertDispatched(ReservationCancelled::class);

        // The dates are sellable again.
        $replacement = $this->service->create($this->request(3, 30));
        $this->assertSame(ReservationStatus::Confirmed, $replacement->status);
    }

    public function test_a_refund_never_exceeds_what_was_actually_collected(): void
    {
        $policy = CancellationPolicy::query()->where('slug', 'flexible')->firstOrFail();
        $this->listing->forceFill(['cancellation_policy_id' => $policy->getKey()])->save();

        $reservation = $this->service->create($this->request(3, 30));

        // The guest only paid a deposit.
        $reservation->forceFill(['paid_total' => 10000])->save();

        $refund = $this->service->calculateCancellationRefund($reservation->refresh());

        $this->assertSame(10000, $refund['refund']->minorUnits);
    }

    public function test_the_policy_is_snapshotted_so_later_edits_do_not_change_the_guests_terms(): void
    {
        $policy = CancellationPolicy::query()->where('slug', 'flexible')->firstOrFail();
        $this->listing->forceFill(['cancellation_policy_id' => $policy->getKey()])->save();

        $reservation = $this->service->create($this->request(3, 30));

        $this->assertNotNull($reservation->cancellation_policy_snapshot);
        $this->assertSame('Flexible', $reservation->cancellation_policy_snapshot['name']);
        $this->assertSame(1, $reservation->cancellation_policy_snapshot['free_cancellation_days']);

        // The manager tightens the policy afterwards.
        $policy->forceFill(['free_cancellation_days' => 0, 'tiers' => [['days_before' => 0, 'refund_percent' => 0]]])->save();

        // The booking still carries the terms that were agreed.
        $this->assertSame(1, $reservation->refresh()->cancellation_policy_snapshot['free_cancellation_days']);
    }

    public function test_a_cancelled_reservation_can_be_reinstated_when_the_dates_are_still_free(): void
    {
        $reservation = $this->service->create($this->request(3, 30));

        $this->service->cancel($reservation, 'Mistake');
        $this->assertSame(ReservationStatus::Cancelled, $reservation->refresh()->status);

        $this->service->reinstate($reservation, 'Cancelled in error');

        $this->assertSame(ReservationStatus::Confirmed, $reservation->refresh()->status);
        $this->assertNull($reservation->cancelled_at);
    }

    public function test_reinstating_is_refused_when_the_dates_have_been_resold(): void
    {
        $reservation = $this->service->create($this->request(3, 30));
        $this->service->cancel($reservation, 'Change of plans');

        // Somebody else takes the nights.
        $this->service->create($this->request(3, 30));

        $this->expectException(DatesUnavailableException::class);

        $this->service->reinstate($reservation->refresh());
    }

    public function test_a_manual_charge_is_added_and_survives_a_reprice(): void
    {
        $reservation = $this->service->create($this->request(3, 30));

        $this->service->addCharge(
            $reservation,
            ReservationCharge::KIND_DAMAGE,
            'Broken lamp',
            Money::of(4500, 'EUR'),
        );

        $reservation->refresh();
        $this->assertSame(35000 + 4500, (int) $reservation->grand_total);

        // Re-pricing must not wipe out an operator's manual line.
        $this->service->modify($reservation, ['check_out' => $this->futureDate(34)]);

        $reservation->refresh();

        $this->assertSame(
            1,
            $reservation->charges()->where('kind', ReservationCharge::KIND_DAMAGE)->count(),
        );
        $this->assertSame(40000 + 5000 + 4500, (int) $reservation->grand_total);
    }

    public function test_a_discount_charge_reduces_the_total(): void
    {
        $reservation = $this->service->create($this->request(2, 30));

        $before = (int) $reservation->grand_total;

        $this->service->addCharge(
            $reservation,
            ReservationCharge::KIND_DISCOUNT,
            'Goodwill gesture',
            Money::of(2500, 'EUR'),
        );

        $this->assertSame($before - 2500, (int) $reservation->refresh()->grand_total);
    }

    public function test_totals_are_always_derivable_from_the_lines(): void
    {
        $reservation = $this->service->create($this->request(4, 30));

        $nightsSum = (int) $reservation->stayNights()->sum('rate_amount');
        $feesSum = (int) $reservation->charges()->where('kind', ReservationCharge::KIND_FEE)->sum('amount');
        $taxSum = (int) $reservation->charges()->where('kind', ReservationCharge::KIND_TAX)->sum('amount');
        $discountSum = (int) $reservation->charges()->where('kind', ReservationCharge::KIND_DISCOUNT)->sum('amount');

        $this->assertSame($nightsSum, (int) $reservation->accommodation_total);
        $this->assertSame($feesSum, (int) $reservation->fees_total);
        $this->assertSame(
            $nightsSum + $feesSum + $taxSum + $discountSum,
            (int) $reservation->grand_total,
        );
    }

    // ------------------------------------------------------------------

    private function request(
        int $nights,
        int $startOffset = 20,
        ?array $guest = null,
        ReservationStatus $status = ReservationStatus::Confirmed,
    ): ReservationRequest {
        return new ReservationRequest(
            listing: $this->listing,
            checkIn: $this->futureDate($startOffset),
            checkOut: $this->futureDate($startOffset + $nights),
            adults: 2,
            status: $status,
            guestAttributes: $guest ?? [
                'first_name' => 'Test',
                'last_name' => 'Guest',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
            bookedAt: $this->futureDate(0),
        );
    }

    private function futureDate(int $offsetDays): CarbonImmutable
    {
        return CarbonImmutable::now($this->property->timezone)->startOfDay()->addDays($offsetDays);
    }
}
