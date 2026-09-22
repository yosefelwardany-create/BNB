<?php

declare(strict_types=1);

namespace App\Domain\Operations\Services;

use App\Domain\Operations\Enums\TaskKind;
use App\Domain\Operations\Models\ChecklistTemplate;
use App\Domain\Operations\Models\Task;
use App\Domain\Operations\Models\TaskRecurrence;
use App\Domain\Properties\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Turns recurrence definitions into actual work.
 *
 * Occurrences are computed from the definition rather than pre-generated, so
 * changing a schedule takes effect immediately instead of leaving a queue of
 * jobs created under the old rules. This only materialises the next short
 * window — far enough ahead that a rota can be planned, near enough that a
 * changed schedule is not fighting a backlog.
 *
 * Each generated task carries a `generation_key` of (recurrence, date), and
 * the unique index behind it is what makes running this twice a no-op.
 */
class RecurrenceGenerator
{
    public function __construct(private readonly TaskService $tasks) {}

    /**
     * Generate work for every active recurrence in the current tenant.
     *
     * @return array{created: int, skipped: int}
     */
    public function generate(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $created = 0;
        $skipped = 0;

        foreach (TaskRecurrence::query()->active()->with('property')->cursor() as $recurrence) {
            $result = $this->generateFor($recurrence, $from, $to);

            $created += $result['created'];
            $skipped += $result['skipped'];
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Generate the occurrences of one recurrence within a window.
     *
     * @return array{created: int, skipped: int}
     */
    public function generateFor(
        TaskRecurrence $recurrence,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $property = $recurrence->property;

        if ($property === null) {
            // A recurrence with no property cannot produce work: there is
            // nowhere for anyone to go.
            return ['created' => 0, 'skipped' => 0];
        }

        $created = 0;
        $skipped = 0;
        $latest = $recurrence->last_generated_on;

        foreach ($recurrence->occurrencesBetween($from, $to) as $date) {
            $task = $this->createOccurrence($recurrence, $property, $date);

            $task === null ? $skipped++ : $created++;

            if ($latest === null || $date->greaterThan($latest)) {
                $latest = $date;
            }
        }

        if ($latest !== null && $latest != $recurrence->last_generated_on) {
            // A watermark for reporting and for the editor, not for
            // correctness: the generation key is what prevents duplicates.
            $recurrence->forceFill(['last_generated_on' => $latest])->save();
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Returns null when the occurrence already exists, which is the normal
     * outcome of a re-run.
     */
    private function createOccurrence(
        TaskRecurrence $recurrence,
        Property $property,
        CarbonImmutable $date,
    ): ?Task {
        $key = sprintf('recurrence:%s:%s', $recurrence->getKey(), $date->toDateString());

        if (Task::query()->where('generation_key', $key)->exists()) {
            return null;
        }

        // The scheduled time is the recurrence's time of day in the
        // *property's* clock. A portfolio spanning timezones has no single
        // 09:00, and a gardener told to arrive at the server's idea of it
        // turns up in the dark.
        $start = $this->startMoment($recurrence, $property, $date);

        $checklist = $recurrence->checklist_template_id === null
            ? null
            : ChecklistTemplate::query()->find($recurrence->checklist_template_id);

        $duration = (int) ($recurrence->estimated_minutes ?: 60);

        try {
            return $this->tasks->create([
                'kind' => TaskKind::tryFrom((string) $recurrence->kind) ?? TaskKind::Custom,
                'title' => $recurrence->name,
                'description' => sprintf('Recurring work: %s.', $recurrence->name),
                'property' => $property,
                'unit_id' => $recurrence->unit_id,
                'scheduled_start' => $start,
                'scheduled_end' => $start->addMinutes($duration),
                'due_at' => $start->endOfDay(),
                'estimated_minutes' => $duration,
                'team_id' => $recurrence->team_id,
                'assigned_to_id' => $recurrence->assigned_to_id,
                'vendor_id' => $recurrence->vendor_id,
                'recurrence_id' => $recurrence->getKey(),
                'generated_by' => 'recurrence',
                'generation_key' => $key,
            ], $checklist);
        } catch (QueryException $exception) {
            // Another worker won the race; the task exists, which is what we
            // wanted.
            if (in_array($exception->getCode(), ['23505', '23000'], true)) {
                return null;
            }

            throw $exception;
        }
    }

    private function startMoment(
        TaskRecurrence $recurrence,
        Property $property,
        CarbonImmutable $date,
    ): CarbonImmutable {
        $timezone = $property->timezone ?: config('app.timezone');

        [$hour, $minute] = array_pad(
            array_map('intval', explode(':', (string) ($recurrence->time_of_day ?? '09:00'))),
            2,
            0,
        );

        return $date->setTimezone($timezone)->setTime($hour, $minute)->utc();
    }
}
