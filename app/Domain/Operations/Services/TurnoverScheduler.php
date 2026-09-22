<?php

declare(strict_types=1);

namespace App\Domain\Operations\Services;

use App\Domain\Operations\Enums\TaskKind;
use App\Domain\Operations\Enums\TaskPriority;
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

        $checkOut = $reservation->departureMoment();
        $nextArrival = $this->nextArrivalAfter($reservation);

        // A same-day turnover is urgent and must finish before the next guest
        // arrives; anything else has the rest of the day.
        $isSameDay = $nextArrival !== null
            && $nextArrival->toDateString() === $reservation->check_out_date->toDateString();

        $duration = (int) ($property->cleaning_duration_minutes ?: 120);

        $deadline = $nextArrival ?? $checkOut->endOfDay();

        // Start the clean right after checkout, and pull it earlier if the
        // window is too tight to fit the work.
        $start = $checkOut;

        if ($deadline->lessThan($start->addMinutes($duration))) {
            $start = $deadline->subMinutes($duration);

            if ($start->lessThan($checkOut)) {
                $start = $checkOut;
            }
        }

        $template = ChecklistTemplate::resolveFor(
            $property->organization_id,
            TaskKind::Cleaning->value,
            $property->getKey(),
        );

        try {
            return $this->tasks->create([
                'kind' => TaskKind::Cleaning,
                'title' => $isSameDay
                    ? sprintf('Same-day turnover — %s', $property->displayName())
                    : sprintf('Departure clean — %s', $property->displayName()),
                'description' => $this->describe($reservation, $nextArrival, $isSameDay),
                'property' => $property,
                'unit_id' => $reservation->unit_id,
                'reservation_id' => $reservation->getKey(),
                'priority' => $isSameDay ? TaskPriority::Urgent : TaskPriority::Normal,
                'scheduled_start' => $start,
                'scheduled_end' => $start->addMinutes($duration),
                'due_at' => $deadline,
                'estimated_minutes' => $duration,
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
                \App\Domain\Operations\Enums\TaskStatus::Cancelled,
                'The reservation was cancelled.',
            );

            return $task;
        }

        $checkOut = $reservation->departureMoment();
        $duration = (int) ($task->estimated_minutes ?: 120);
        $nextArrival = $this->nextArrivalAfter($reservation);

        $task->forceFill([
            'scheduled_start' => $checkOut,
            'scheduled_end' => $checkOut->addMinutes($duration),
            'due_at' => $nextArrival ?? $checkOut->endOfDay(),
            'unit_id' => $reservation->unit_id,
        ])->save();

        return $task;
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
