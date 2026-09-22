<?php

declare(strict_types=1);

namespace App\Domain\Operations\Services;

use App\Domain\Operations\Enums\TaskKind;
use App\Domain\Operations\Enums\TaskPriority;
use App\Domain\Operations\Enums\TaskStatus;
use App\Domain\Operations\Models\ChecklistTemplate;
use App\Domain\Operations\Models\Task;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Turns departures into cleaning work.
 *
 * The scheduling detail that matters is the gap. A turnover where the next
 * guest arrives the same afternoon is a different job from one with three
 * empty days after it: the first is urgent and has a hard deadline at the next
 * check-in, the second can be done at leisure. The generator works this out
 * from the calendar rather than making every clean identical.
 *
 * Generation is idempotent. Each task carries a `generation_key` derived from
 * the reservation, and the unique index on it means running the generator
 * twice — or racing two workers — cannot produce two cleans for one checkout.
 */
class TurnoverScheduler
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * Create the cleaning task for a departure, if there is not one already.
     *
     * Returns null when the task already exists, which is the normal outcome
     * of a re-run rather than an error.
     */
    public function scheduleTurnover(Reservation $reservation): ?Task
    {
        $property = $reservation->property;

        if ($property === null) {
            return null;
        }

        $key = $this->generationKey($reservation, 'turnover');

        if (Task::query()->where('generation_key', $key)->exists()) {
            return null;
        }

        $plan = $this->plan($reservation, $property);

        $template = ChecklistTemplate::resolveFor(
            $property->organization_id,
            TaskKind::Cleaning->value,
            $property->getKey(),
        );

        try {
            return $this->tasks->create([
                'kind' => TaskKind::Cleaning,
                'title' => $this->title($property, $plan['is_same_day']),
                'description' => $plan['description'],
                'property' => $property,
                'unit_id' => $reservation->unit_id,
                'reservation_id' => $reservation->getKey(),
                'priority' => $plan['priority'],
                'scheduled_start' => $plan['start'],
                'scheduled_end' => $plan['end'],
                'due_at' => $plan['due_at'],
                'estimated_minutes' => $plan['duration'],
                'billable_to' => 'guest',
                'generated_by' => 'turnover_scheduler',
                'generation_key' => $key,
            ], $template);
        } catch (QueryException $exception) {
            // Another worker won the race; the task exists, which is the
            // outcome we wanted.
            if ($this->isUniqueViolation($exception)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Generate turnovers for every departure in a window.
     *
     * Run by the scheduler. The window is deliberately short and forward
     * looking: creating cleans months ahead would mean regenerating them every
     * time a booking moved.
     *
     * @return array{created: int, skipped: int}
     */
    public function generateForWindow(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $created = 0;
        $skipped = 0;

        Reservation::query()
            ->with(['property', 'unit'])
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
            ->whereBetween('check_out_date', [$from->toDateString(), $to->toDateString()])
            ->chunkById(200, function ($reservations) use (&$created, &$skipped): void {
                foreach ($reservations as $reservation) {
                    $task = $this->scheduleTurnover($reservation);

                    $task === null ? $skipped++ : $created++;
                }
            });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Move or cancel the turnover when a booking's dates change.
     *
     * A clean left at the old date is worse than no clean at all: somebody
     * turns up to an occupied flat, and nobody turns up to the empty one.
     */
    public function rescheduleFor(Reservation $reservation): ?Task
    {
        $task = Task::query()
            ->where('generation_key', $this->generationKey($reservation, 'turnover'))
            ->first();

        if ($task === null) {
            return $this->scheduleTurnover($reservation);
        }

        // Work already under way is left alone; a cleaner mid-job should not
        // have the ground moved beneath them.
        if (! $task->status->isOpen() || $task->started_at !== null) {
            return $task;
        }

        if ($reservation->status->isCancelled()) {
            $this->tasks->transitionTo(
                $task,
                TaskStatus::Cancelled,
                'The reservation was cancelled.',
            );

            return $task;
        }

        $property = $reservation->property;

        if ($property === null) {
            return $task;
        }

        $plan = $this->plan($reservation, $property, (int) ($task->estimated_minutes ?: 0));

        // The priority and the title are recomputed, not just the dates. The
        // gap after a departure is what makes a clean urgent, and that gap
        // changes when a booking either side of it moves.
        $task->forceFill([
            'title' => $this->title($property, $plan['is_same_day']),
            'description' => $plan['description'],
            'priority' => $plan['priority'],
            'scheduled_start' => $plan['start'],
            'scheduled_end' => $plan['end'],
            'due_at' => $plan['due_at'],
            'unit_id' => $reservation->unit_id,
        ])->save();

        return $task;
    }

    /**
     * Bring the clean before an arrival up to date.
     *
     * The case this exists for: a departure is booked with nothing after it,
     * so its clean is scheduled as ordinary work with the rest of the day. A
     * guest then books the very same day. Nothing about the departing
     * reservation changed, so nothing would have revisited its clean — and the
     * urgent turnover would sit on the board as a normal one until somebody
     * noticed, which is exactly the day nobody does.
     */
    public function refreshTurnoverBefore(Reservation $arrival): ?Task
    {
        $preceding = Reservation::query()
            ->where('property_id', $arrival->property_id)
            ->when(
                $arrival->unit_id !== null,
                fn ($q) => $q->where('unit_id', $arrival->unit_id),
            )
            ->blocking()
            ->whereKeyNot($arrival->getKey())
            ->where('check_out_date', '<=', $arrival->check_in_date->toDateString())
            ->orderByDesc('check_out_date')
            ->first();

        if ($preceding === null) {
            return null;
        }

        return $this->rescheduleFor($preceding);
    }

    /**
     * Work out when the clean happens and how urgent it is.
     *
     * The gap is everything. A turnover with the next guest arriving the same
     * afternoon is a different job from one with three empty days after it:
     * the first is urgent and has a hard deadline at the next check-in, the
     * second can be done at leisure.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, due_at: CarbonImmutable, duration: int, priority: TaskPriority, is_same_day: bool, description: string}
     */
    private function plan(Reservation $reservation, object $property, int $durationOverride = 0): array
    {
        $checkOut = $reservation->departureMoment();
        $nextArrival = $this->nextArrivalAfter($reservation);

        $isSameDay = $nextArrival !== null
            && $nextArrival->toDateString() === $reservation->check_out_date->toDateString();

        $duration = $durationOverride > 0
            ? $durationOverride
            : (int) ($property->cleaning_duration_minutes ?: 120);

        $deadline = $nextArrival ?? $checkOut->endOfDay();

        // Start right after checkout, and pull it earlier if the window is too
        // tight to fit the work — but never before the guest has left.
        $start = $checkOut;

        if ($deadline->lessThan($start->addMinutes($duration))) {
            $start = $deadline->subMinutes($duration);

            if ($start->lessThan($checkOut)) {
                $start = $checkOut;
            }
        }

        return [
            'start' => $start,
            'end' => $start->addMinutes($duration),
            'due_at' => $deadline,
            'duration' => $duration,
            'priority' => $isSameDay ? TaskPriority::Urgent : TaskPriority::Normal,
            'is_same_day' => $isSameDay,
            'description' => $this->describe($reservation, $nextArrival, $isSameDay),
        ];
    }

    private function title(object $property, bool $isSameDay): string
    {
        return $isSameDay
            ? sprintf('Same-day turnover — %s', $property->displayName())
            : sprintf('Departure clean — %s', $property->displayName());
    }

    /**
     * When the next guest arrives in the same space, if anyone does soon.
     */
    private function nextArrivalAfter(Reservation $reservation): ?CarbonImmutable
    {
        $next = Reservation::query()
            ->where('property_id', $reservation->property_id)
            ->when(
                $reservation->unit_id !== null,
                fn ($q) => $q->where('unit_id', $reservation->unit_id),
            )
            ->blocking()
            ->whereKeyNot($reservation->getKey())
            ->where('check_in_date', '>=', $reservation->check_out_date->toDateString())
            ->orderBy('check_in_date')
            ->first();

        return $next?->arrivalMoment();
    }

    private function describe(Reservation $reservation, ?CarbonImmutable $nextArrival, bool $isSameDay): string
    {
        $lines = [
            sprintf('Departure: %s', $reservation->departureMoment()->format('D j M Y, H:i')),
        ];

        if ($nextArrival !== null) {
            $lines[] = sprintf('Next arrival: %s', $nextArrival->format('D j M Y, H:i'));
        } else {
            $lines[] = 'No arrival is booked after this departure.';
        }

        if ($isSameDay) {
            $lines[] = 'Same-day turnover — the property must be ready before the next guest arrives.';
        }

        $lines[] = sprintf('Booking %s.', $reservation->confirmation_code);

        return implode("\n", $lines);
    }

    private function generationKey(Reservation $reservation, string $purpose): string
    {
        return sprintf('%s:%s', $purpose, $reservation->getKey());
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23505', '23000'], true);
    }
}
