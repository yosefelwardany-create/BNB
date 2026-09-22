<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Operations;

use App\Domain\Operations\Enums\TaskKind;
use App\Domain\Operations\Models\TaskRecurrence;
use App\Domain\Operations\Services\RecurrenceGenerator;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskRecurrenceResource;
use App\Http\Resources\TaskResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Repeating work: a weekly garden visit, a quarterly boiler service.
 */
class TaskRecurrenceController extends Controller
{
    public function __construct(private readonly RecurrenceGenerator $generator) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TaskRecurrence::class);

        $query = TaskRecurrence::query();

        if ($request->boolean('active_only', true)) {
            $query->active();
        }

        if ($request->filled('property_id')) {
            $query->where('property_id', $request->string('property_id')->toString());
        }

        return TaskRecurrenceResource::collection($query->orderBy('name')->paginate($this->perPage()));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', TaskRecurrence::class);

        $recurrence = new TaskRecurrence;
        $recurrence->fill($request->validate($this->rules()));
        $recurrence->organization_id = $this->organization()->getKey();
        $recurrence->save();

        return (new TaskRecurrenceResource($recurrence))->response()->setStatusCode(201);
    }

    public function show(TaskRecurrence $recurrence): TaskRecurrenceResource
    {
        $this->authorize('view', $recurrence);

        return new TaskRecurrenceResource($recurrence);
    }

    public function update(Request $request, TaskRecurrence $recurrence): TaskRecurrenceResource
    {
        $this->authorize('update', $recurrence);

        $recurrence->fill($request->validate($this->rules($recurrence)))->save();

        return new TaskRecurrenceResource($recurrence->fresh());
    }

    /**
     * Materialise the next occurrences now rather than waiting for the
     * scheduler. Idempotent, so pressing it twice changes nothing.
     */
    public function generate(Request $request, TaskRecurrence $recurrence): JsonResponse
    {
        $this->authorize('update', $recurrence);

        $days = max(1, min((int) $request->integer('days', 30), 365));

        $result = $this->generator->generateFor(
            $recurrence,
            CarbonImmutable::today(),
            CarbonImmutable::today()->addDays($days),
        );

        return response()->json([
            'created' => $result['created'],
            'already_existed' => $result['skipped'],
            'data' => TaskResource::collection(
                $recurrence->tasks()->latest()->limit(20)->get(),
            )->resolve(),
        ]);
    }

    /**
     * Stop a recurrence. Work it already created is left alone — somebody may
     * be on their way to it.
     */
    public function destroy(TaskRecurrence $recurrence): JsonResponse
    {
        $this->authorize('delete', $recurrence);

        $recurrence->forceFill(['is_active' => false])->save();

        return response()->json([
            'message' => 'The schedule has been stopped. Work already created is unchanged.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?TaskRecurrence $recurrence = null): array
    {
        $creating = $recurrence === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'kind' => [$creating ? 'required' : 'sometimes', Rule::enum(TaskKind::class)],
            'property_id' => [$creating ? 'required' : 'sometimes', 'string', 'exists:properties,id'],
            'unit_id' => ['sometimes', 'nullable', 'string', 'exists:units,id'],

            'frequency' => [
                $creating ? 'required' : 'sometimes',
                Rule::in(['daily', 'weekly', 'monthly', 'quarterly', 'yearly']),
            ],
            'interval' => ['sometimes', 'integer', 'min:1', 'max:52'],
            'days_of_week' => ['sometimes', 'nullable', 'array'],
            'days_of_week.*' => ['integer', 'min:0', 'max:6'],
            'day_of_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'time_of_day' => ['sometimes', 'nullable', 'date_format:H:i'],

            'checklist_template_id' => ['sometimes', 'nullable', 'string', 'exists:checklist_templates,id'],
            'team_id' => ['sometimes', 'nullable', 'string', 'exists:teams,id'],
            'assigned_to_id' => ['sometimes', 'nullable', 'string', 'exists:users,id'],
            'vendor_id' => ['sometimes', 'nullable', 'string', 'exists:vendors,id'],
            'estimated_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],

            'starts_on' => [$creating ? 'required' : 'sometimes', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after:starts_on'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
