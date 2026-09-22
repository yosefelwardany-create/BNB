<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Domain\Listings\Models\Listing;
use App\Domain\Payments\Models\PaymentSchedule;
use App\Domain\Payments\Services\PaymentScheduleService;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * When a booking's money is due.
 *
 * Two things are being protected. First, that instalments always sum back to
 * the booking exactly — a guest whose deposit and balance do not add up to
 * their total notices immediately, and it is the kind of arithmetic that goes
 * wrong quietly. Second, that nothing is charged automatically unless the
 * guest agreed to it.
 */
class PaymentScheduleTest extends TestCase
{
    use RefreshDatabase;

    private PaymentScheduleService $schedules;

    private Property $property;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schedules = $this->app->make(PaymentScheduleService::class);

        $organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'cleaning_fee' => 5000,
            'max_occupancy' => 4,
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);
    }

    public function test_the_standard_plan_splits_the_booking_without_losing_a_cent(): void
    {
        $reservation = $this->book();

        $plan = $this->schedules->standardPlan($reservation, 30.0, 14);

        $this->assertCount(2, $plan);

        // The whole point: parts that sum back to the total exactly. Computing
        // each percentage independently and rounding loses or invents a minor
        // unit on most totals.
        $this->assertSame(
            (int) $reservation->grand_total,
            (int) $plan->sum('amount'),
        );

        $this->assertSame('Deposit', $plan[0]->label);
        $this->assertSame('Balance', $plan[1]->label);
    }

    public function test_an_awkward_total_still_sums_back_exactly(): void
    {
        $reservation = $this->book();

        // A total that does not divide cleanly by three is where naive
        // percentage arithmetic goes wrong.
        $reservation->forceFill(['grand_total' => 100001])->save();

        $plan = $this->schedules->plan($reservation, [
            ['label' => 'First', 'due_on' => CarbonImmutable::today(), 'percent' => 33.33],
            ['label' => 'Second', 'due_on' => CarbonImmutable::today()->addDays(7), 'percent' => 33.33],
            ['label' => 'Third', 'due_on' => CarbonImmutable::today()->addDays(14), 'percent' => 33.34],
        ]);

        $this->assertSame(100001, (int) $plan->sum('amount'));
    }

    public function test_a_fixed_deposit_is_taken_first_and_the_rest_allocated(): void
    {
        $reservation = $this->book();
        $total = (int) $reservation->grand_total;

        $plan = $this->schedules->plan($reservation, [
            ['label' => 'Fixed deposit', 'due_on' => CarbonImmutable::today(), 'amount' => 20000],
            ['label' => 'Balance', 'due_on' => CarbonImmutable::today()->addDays(10), 'percent' => 100],
        ]);

        $this->assertSame(20000, (int) $plan[0]->amount);
        $this->assertSame($total - 20000, (int) $plan[1]->amount);
        $this->assertSame($total, (int) $plan->sum('amount'));
    }

    public function test_a_booking_made_inside_the_balance_window_owes_the_balance_today(): void
    {
        // Arriving in four days, with a fourteen-day balance rule: the balance
        // date has already passed, and a due date in the past is nonsense.
        $reservation = $this->book(4, 7);

        $plan = $this->schedules->standardPlan($reservation, 30.0, 14);

        $this->assertTrue($plan[1]->due_on->greaterThanOrEqualTo(CarbonImmutable::today()));
    }

    public function test_replanning_leaves_paid_instalments_alone(): void
    {
        $reservation = $this->book();

        $plan = $this->schedules->standardPlan($reservation, 30.0, 14);
        $deposit = $plan[0];

        $this->schedules->settle($deposit, $deposit->amount());

        $this->assertSame('paid', $deposit->fresh()->status);

        $replanned = $this->schedules->plan($reservation, [
            ['label' => 'Revised balance', 'due_on' => CarbonImmutable::today()->addDays(3), 'percent' => 100],
        ]);

        // The paid deposit survives, and the new plan covers only what is left.
        $this->assertTrue($replanned->contains(fn (PaymentSchedule $s): bool => $s->is($deposit)));
        $this->assertSame(
            (int) $reservation->grand_total,
            (int) $replanned->sum('amount'),
        );
    }

    public function test_charging_an_instalment_takes_the_money_and_settles_the_row(): void
    {
        $reservation = $this->book();
        $plan = $this->schedules->standardPlan($reservation, 30.0, 14);
        $deposit = $plan[0];

        $payment = $this->schedules->charge($deposit);

        $this->assertSame((int) $deposit->amount, $payment->capturedAmount()->minorUnits);
        $this->assertSame('paid', $deposit->fresh()->status);
        $this->assertSame(1, (int) $deposit->fresh()->attempts);

        // The payment points back at the instalment it satisfied, so
        // reconciliation never has to guess.
        $this->assertSame($deposit->getKey(), $payment->metadata['payment_schedule_id'] ?? null);
    }

    public function test_a_partial_settlement_leaves_the_instalment_partially_paid(): void
    {
        $reservation = $this->book();
        $deposit = $this->schedules->standardPlan($reservation, 30.0, 14)[0];

        $this->schedules->settle($deposit, Money::of(1000, 'EUR'));

        $this->assertSame('partially_paid', $deposit->fresh()->status);
        $this->assertSame(1000, (int) $deposit->fresh()->paid_amount);
        $this->assertTrue($deposit->fresh()->outstandingAmount()->isPositive());
    }

    public function test_the_sweep_only_charges_what_the_guest_agreed_to(): void
    {
        $reservation = $this->book();

        // Auto-charge off: taking a stored card without the guest pressing
        // anything is something they agreed to at booking, never a default.
        $this->schedules->standardPlan($reservation, 30.0, 14, autoCharge: false);

        $result = $this->schedules->processDue(CarbonImmutable::today()->toDateString());

        $this->assertSame(0, $result['charged']);

        // A different window: the same property cannot be sold twice.
        $other = $this->book(40, 43);
        $this->schedules->standardPlan($other, 30.0, 14, autoCharge: true);

        $result = $this->schedules->processDue(CarbonImmutable::today()->toDateString());

        $this->assertSame(1, $result['charged']);
    }

    public function test_the_sweep_does_not_charge_a_cancelled_booking(): void
    {
        $reservation = $this->book();
        $this->schedules->standardPlan($reservation, 30.0, 14, autoCharge: true);

        $reservation->forceFill(['status' => ReservationStatus::Cancelled->value])->save();

        $result = $this->schedules->processDue(CarbonImmutable::today()->toDateString());

        // Charging it would be taking money for a stay that is not happening.
        $this->assertSame(0, $result['charged']);
        $this->assertSame(
            'cancelled',
            PaymentSchedule::query()->where('reservation_id', $reservation->getKey())->first()->status,
        );
    }

    public function test_anything_left_outstanding_past_its_date_is_marked_overdue(): void
    {
        $reservation = $this->book();
        $this->schedules->standardPlan($reservation, 30.0, 14);

        $result = $this->schedules->processDue(CarbonImmutable::today()->addDay()->toDateString());

        // Whether or not it was ever going to be charged automatically,
        // somebody has to chase it.
        $this->assertGreaterThanOrEqual(1, $result['marked_overdue']);
    }

    private function book(int $inDays = 20, int $outDays = 23): Reservation
    {
        return $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $this->listing,
            checkIn: CarbonImmutable::today()->addDays($inDays),
            checkOut: CarbonImmutable::today()->addDays($outDays),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Marta',
                'last_name' => 'Silva',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
            bookedAt: CarbonImmutable::today(),
        ));
    }
}
