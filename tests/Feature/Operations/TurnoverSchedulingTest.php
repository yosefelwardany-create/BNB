<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Domain\Listings\Models\Listing;
use App\Domain\Operations\Enums\TaskKind;
use App\Domain\Operations\Enums\TaskPriority;
use App\Domain\Operations\Enums\TaskStatus;
use App\Domain\Operations\Models\Task;
use App\Domain\Operations\Services\TurnoverScheduler;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cleaning follows the booking calendar.
 *
 * The scheduling detail that matters is the gap. A turnover where the next
 * guest arrives the same afternoon is a different job from one with three
 * empty days after it, and a generator that made every clean identical would
 * be worse than useless on the day it mattered.
 */
class TurnoverSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private TurnoverScheduler $scheduler;

    private ReservationService $reservations;

    private Property $property;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scheduler = $this->app->make(TurnoverScheduler::class);
        $this->reservations = $this->app->make(ReservationService::class);

        $organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'max_occupancy' => 4,
            'cleaning_duration_minutes' => 120,
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);
    }

    public function test_confirming_a_booking_schedules_its_departure_clean(): void
    {
        $reservation = $this->book(20, 3);

        // Nothing in this test asked for a clean. The reservation service does
        // not know operations exist; the domain event listener did it, which is
        // the connective tissue this whole design is for.
        $task = $this->turnoverFor($reservation);

        $this->assertNotNull($task);
        $this->assertSame(TaskKind::Cleaning, $task->kind);
        $this->assertSame($reservation->getKey(), $task->reservation_id);

        // Nobody arrives after this stay, so it is ordinary work with the rest
        // of the day to do it.
        $this->assertSame(TaskPriority::Normal, $task->priority);
        $this->assertStringContainsString('Departure clean', $task->title);
    }

    public function test_a_same_day_turnover_is_urgent_and_due_before_the_next_arrival(): void
    {
        $departing = $this->book(20, 3);

        // Its clean was scheduled as ordinary work: at this point nobody is
        // arriving.
        $this->assertSame(TaskPriority::Normal, $this->turnoverFor($departing)->priority);

        // The next guest then books the day this one leaves. Nothing about the
        // departing reservation changed, so only the arrival-side refresh can
        // catch this.
        $arriving = $this->book(23, 2);

        $task = $this->turnoverFor($departing);

        $this->assertNotNull($task);
        $this->assertSame(TaskPriority::Urgent, $task->priority);
        $this->assertStringContainsString('Same-day turnover', $task->title);

        // The hard deadline is the next guest's arrival, not the end of the
        // day: a clean finished at 23:00 is a clean that failed.
        $this->assertNotNull($task->due_at);
        $this->assertTrue($task->due_at->equalTo($arriving->arrivalMoment()));

        // And the work is pulled early enough to actually fit in the window.
        $this->assertTrue($task->scheduled_end->lessThanOrEqualTo($task->due_at));
    }

    public function test_generation_is_idempotent(): void
    {
        $reservation = $this->book(20, 3);

        $this->assertNotNull($this->turnoverFor($reservation));

        // A re-run — a replayed event, a second worker — changes nothing.
        $this->assertNull($this->scheduler->scheduleTurnover($reservation));
        $this->assertNull($this->scheduler->scheduleTurnover($reservation));

        $this->assertSame(1, Task::query()->where('kind', TaskKind::Cleaning->value)->count());
    }

    public function test_cancelling_the_arrival_relaxes_the_clean_before_it(): void
    {
        $departing = $this->book(20, 3);
        $arriving = $this->book(23, 2);

        $this->assertSame(TaskPriority::Urgent, $this->turnoverFor($departing)->priority);

        $this->reservations->cancel($arriving, 'Guest cancelled');

        // The pressure is off: nobody is coming that afternoon any more.
        $refreshed = $this->turnoverFor($departing);

        $this->assertSame(TaskPriority::Normal, $refreshed->priority);
        $this->assertStringContainsString('Departure clean', $refreshed->title);
    }

    public function test_moving_a_booking_moves_its_clean(): void
    {
        $reservation = $this->book(20, 3);

        $task = $this->turnoverFor($reservation);
        $originalStart = $task->scheduled_start;

        $this->reservations->modify($reservation, [
            'check_in' => $this->date(30),
            'check_out' => $this->date(34),
        ]);

        $task->refresh();

        // A clean left at the old date is worse than no clean at all: somebody
        // turns up to an occupied flat and nobody turns up to the empty one.
        $this->assertFalse($task->scheduled_start->equalTo($originalStart));
        $this->assertTrue($task->scheduled_start->equalTo($reservation->fresh()->departureMoment()));
    }

    public function test_cancelling_a_booking_cancels_its_clean(): void
    {
        $reservation = $this->book(20, 3);

        $task = $this->turnoverFor($reservation);

        $this->reservations->cancel($reservation, 'Guest cancelled');

        $this->assertSame(TaskStatus::Cancelled, $task->fresh()->status);
    }

    public function test_work_already_started_is_not_moved_under_the_cleaner(): void
    {
        $reservation = $this->book(20, 3);

        $task = $this->turnoverFor($reservation);

        $task->forceFill([
            'status' => TaskStatus::InProgress,
            'started_at' => now(),
        ])->save();

        $originalStart = $task->fresh()->scheduled_start;

        $this->reservations->modify($reservation, [
            'check_in' => $this->date(30),
            'check_out' => $this->date(34),
        ]);

        // Somebody is mid-job. Moving the ground beneath them helps nobody.
        $this->assertTrue($task->fresh()->scheduled_start->equalTo($originalStart));
    }

    public function test_the_window_generator_is_a_safety_net_that_creates_nothing_twice(): void
    {
        $this->book(5, 2);
        $this->book(10, 2);
        $this->book(40, 2);

        // The listener already covered all three, so the generator — the net
        // for bookings imported from a channel, or a job the queue lost —
        // finds nothing left to do inside its window.
        $result = $this->scheduler->generateForWindow(
            CarbonImmutable::today(),
            CarbonImmutable::today()->addDays(14),
        );

        $this->assertSame(0, $result['created']);
        $this->assertSame(2, $result['skipped']);

        // And a clean deleted out from under it is recreated rather than lost.
        $orphaned = Task::query()->where('kind', TaskKind::Cleaning->value)->first();
        $orphaned->forceDelete();

        $recovered = $this->scheduler->generateForWindow(
            CarbonImmutable::today(),
            CarbonImmutable::today()->addDays(14),
        );

        $this->assertSame(1, $recovered['created']);
    }

    // ------------------------------------------------------------------

    /**
     * The clean the listener created for a reservation.
     */
    private function turnoverFor(Reservation $reservation): ?Task
    {
        return Task::query()
            ->where('generation_key', 'turnover:'.$reservation->getKey())
            ->first();
    }

    private function book(int $startOffset, int $nights): Reservation
    {
        return $this->reservations->create(new ReservationRequest(
            listing: $this->listing,
            checkIn: $this->date($startOffset),
            checkOut: $this->date($startOffset + $nights),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Test',
                'last_name' => 'Guest',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
            bookedAt: $this->date(0),
        ));
    }

    private function date(int $offsetDays): CarbonImmutable
    {
        return CarbonImmutable::now($this->property->timezone)->startOfDay()->addDays($offsetDays);
    }
}
