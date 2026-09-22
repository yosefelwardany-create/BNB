<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Operations;

use App\Domain\Operations\Enums\TaskKind;
use App\Domain\Operations\Enums\TaskPriority;
use App\Domain\Operations\Enums\TaskStatus;
use App\Domain\Operations\Models\ChecklistTemplate;
use App\Domain\Operations\Models\Task;
use App\Domain\Operations\Models\TaskChecklistItem;
use App\Domain\Operations\Services\TaskService;
use App\Domain\Users\Services\AccessControl;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskChecklistItemResource;
use App\Http\Resources\TaskCommentResource;
use App\Http\Resources\TaskResource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Operational work: cleaning, maintenance, inspections and everything else.
 *
 * The listing is narrowed by what the caller may see before anything is
 * fetched. A housekeeper holding only `tasks.view_own` gets their own rota;
 * filtering after the fact would leak the shape of the portfolio through
 * pagination counts even when the rows themselves were hidden.
 */
class TaskController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly AccessControl $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Task::class);

        $query = Task::query()
            ->with(['property', 'assignee', 'team', 'vendor'])
            ->visibleTo(...$this->visibility());

        $this->applyFilters($query, $request);

        $sort = $request->string('sort', 'scheduled_start')->toString();
        $direction = $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc';

        if (in_array($sort, ['scheduled_start', 'due_at', 'created_at', 'priority', 'status'], true)) {
            // Nulls last on either direction: work with no date belongs at the
            // bottom of a schedule, not at the top of it.
            $query->orderByRaw(sprintf('%s %s NULLS LAST', $sort, $direction));
        }

        return TaskResource::collection($query->paginate($this->perPage()));
    }

    /**
     * A day's work, grouped for the operations board.
     *
     * Returned as one call rather than several because the board is the screen
     * a coordinator keeps open all morning, and three round trips to render it
     * is three chances to show an inconsistent picture.
     */
    public function board(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Task::class);

        $date = $request->filled('date')
            ? CarbonImmutable::parse($request->string('date')->toString())
            : CarbonImmutable::today();

        $tasks = Task::query()
            ->with(['property', 'unit', 'assignee', 'team', 'vendor'])
            ->visibleTo(...$this->visibility())
            ->where(function ($query) use ($date): void {
                $query->whereBetween('scheduled_start', [$date->startOfDay(), $date->endOfDay()])
                    ->orWhereBetween('due_at', [$date->startOfDay(), $date->endOfDay()])
                    // Work that should already have been done stays on the
                    // board until somebody deals with it. Dropping it at
                    // midnight is how an overdue clean gets forgotten.
                    ->orWhere(fn ($q) => $q->open()
                        ->whereNotNull('due_at')
                        ->where('due_at', '<', $date->startOfDay()));
            })
            ->when($request->filled('property_id'), fn ($q) => $q->where('property_id', $request->string('property_id')))
            ->get();

        return response()->json([
            'date' => $date->toDateString(),
            'data' => [
                'unassigned' => TaskResource::collection($tasks->filter(
                    fn (Task $task): bool => ! $task->isAssigned() && $task->status->isOpen(),
                )->values())->resolve(),

                'overdue' => TaskResource::collection($tasks->filter(
                    fn (Task $task): bool => $task->isOverdue(),
                )->values())->resolve(),

                'scheduled' => TaskResource::collection($tasks->filter(
                    fn (Task $task): bool => $task->isAssigned()
                        && $task->status->isOpen()
                        && ! $task->isOverdue(),
                )->values())->resolve(),

                'completed' => TaskResource::collection($tasks->filter(
                    fn (Task $task): bool => $task->status === TaskStatus::Completed,
                )->values())->resolve(),
            ],
            'summary' => [
                'total' => $tasks->count(),
                'open' => $tasks->filter(fn (Task $t): bool => $t->status->isOpen())->count(),
                'overdue' => $tasks->filter(fn (Task $t): bool => $t->isOverdue())->count(),
                'unassigned' => $tasks->filter(
                    fn (Task $t): bool => ! $t->isAssigned() && $t->status->isOpen(),
                )->count(),
                'breaching_sla' => $tasks->filter(fn (Task $t): bool => $t->breachesSla())->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Task::class);

        $data = $request->validate($this->rules());

        $template = isset($data['checklist_template_id'])
            ? ChecklistTemplate::query()->findOrFail($data['checklist_template_id'])
            : null;

        unset($data['checklist_template_id']);

        $task = $this->tasks->create($data, $template);

        return (new TaskResource($task->load(['property', 'checklistItems'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Task $task): TaskResource
    {
        $this->authorize('view', $task);

        return new TaskResource($task->load([
            'property', 'unit', 'assignee', 'team', 'vendor',
            'checklistItems.photos', 'comments.user', 'photos', 'tags',
        ]));
    }

    public function update(Request $request, Task $task): TaskResource
    {
        $this->authorize('update', $task);

        $data = $request->validate($this->rules($task));

        // Assignment and status have their own endpoints, because each has
        // consequences — acceptance resets, follow-up work is raised — that a
        // generic attribute write would skip.
        unset($data['status'], $data['assigned_to_id'], $data['team_id'], $data['vendor_id']);

        $task->fill($data)->save();

        return new TaskResource($task->fresh(['property', 'checklistItems']));
    }

    public function assign(Request $request, Task $task): TaskResource
    {
        $this->authorize('assign', $task);

        $data = $request->validate([
            'assigned_to_id' => ['sometimes', 'nullable', 'string', 'exists:users,id'],
            'team_id' => ['sometimes', 'nullable', 'string', 'exists:teams,id'],
            'vendor_id' => ['sometimes', 'nullable', 'string', 'exists:vendors,id'],
        ]);

        $task = $this->tasks->assign(
            $task,
            $data['assigned_to_id'] ?? null,
            $data['team_id'] ?? null,
            $data['vendor_id'] ?? null,
        );

        return new TaskResource($task->fresh(['assignee', 'team', 'vendor']));
    }

    /**
     * Move a task through its lifecycle.
     */
    public function transition(Request $request, Task $task, string $action): TaskResource
    {
        $target = match ($action) {
            'accept' => TaskStatus::Accepted,
            'start' => TaskStatus::InProgress,
            'block' => TaskStatus::Blocked,
            'unblock' => TaskStatus::InProgress,
            'cancel' => TaskStatus::Cancelled,
            default => abort(404),
        };

        $this->authorize($target === TaskStatus::Cancelled ? 'cancel' : 'update', $task);

        $data = $request->validate([
            'reason' => [$target === TaskStatus::Blocked ? 'required' : 'sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $task = $this->tasks->transitionTo($task, $target, $data['reason'] ?? null);

        return new TaskResource($task->fresh(['property']));
    }

    /**
     * Finish a task.
     *
     * Refused while the checklist has outstanding items or a required
     * photograph is missing. The blockers come back in the error body so the
     * app can say what is left rather than "something went wrong".
     */
    public function complete(Request $request, Task $task): JsonResponse
    {
        $this->authorize('complete', $task);

        $data = $request->validate([
            'actual_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10080'],
            'actual_cost' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Only a supervisor may sign off work with items outstanding, and
            // the override is recorded in the audit entry.
            'force' => ['sometimes', 'boolean'],
        ]);

        $force = (bool) ($data['force'] ?? false);

        if ($force && ! $this->access->allows($this->currentUser(), 'tasks.complete')) {
            abort(403, 'Completing a task with outstanding checklist items requires the complete permission.');
        }

        $blockers = $this->tasks->completionBlockers($task);

        if ($blockers !== [] && ! $force) {
            return response()->json([
                'message' => 'This task cannot be completed yet.',
                'blockers' => $blockers,
            ], 422);
        }

        $task = $this->tasks->complete($task, $data, $force);

        return response()->json([
            'data' => (new TaskResource($task->fresh(['checklistItems', 'property'])))->resolve(),
            'overridden' => $force && $blockers !== [],
        ]);
    }

    /**
     * Record the outcome of one checklist item.
     */
    public function updateChecklistItem(Request $request, Task $task, TaskChecklistItem $item): JsonResponse
    {
        $this->authorize('update', $task);

        abort_unless($item->task_id === $task->getKey(), 404);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                TaskChecklistItem::PENDING,
                TaskChecklistItem::PASSED,
                TaskChecklistItem::FAILED,
                TaskChecklistItem::SKIPPED,
            ])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // A failure without a severity gives the follow-up ticket no
            // priority to inherit, so it is required when something failed.
            'severity' => [
                Rule::requiredIf(fn (): bool => $request->input('status') === TaskChecklistItem::FAILED),
                'nullable',
                Rule::in(['minor', 'moderate', 'major', 'critical']),
            ],
        ]);

        $item = $this->tasks->completeChecklistItem(
            $item,
            $data['status'],
            $data['notes'] ?? null,
            $data['severity'] ?? null,
        );

        return response()->json([
            'data' => (new TaskChecklistItemResource($item->fresh(['photos'])))->resolve(),
            'remaining_blockers' => $this->tasks->completionBlockers($task->fresh()),
        ]);
    }

    /**
     * Add a comment to a task.
     */
    public function storeComment(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'is_internal' => ['sometimes', 'boolean'],
        ]);

        $comment = $task->comments()->create([
            'organization_id' => $task->organization_id,
            'user_id' => $this->currentUser()->getKey(),
            'body' => $data['body'],
            'is_internal' => $data['is_internal'] ?? true,
        ]);

        return (new TaskCommentResource($comment->load('user')))
            ->response()
            ->setStatusCode(201);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The arguments the visibility scope needs for the current caller.
     *
     * @return array{0: string, 1: bool, 2: list<string>, 3: list<string>|null}
     */
    private function visibility(): array
    {
        $user = $this->currentUser();

        return [
            $user->getKey(),
            $this->access->allows($user, 'tasks.view'),
            $user->memberships()
                ->where('organization_id', $this->organization()->getKey())
                ->with('teams:id')
                ->get()
                ->flatMap(fn ($membership): array => $membership->teams->pluck('id')->all())
                ->unique()
                ->values()
                ->all(),
            $this->access->restrictedPropertyIds($user),
        ];
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('status')) {
            $query->whereIn('status', explode(',', $request->string('status')->toString()));
        }

        if ($request->boolean('open_only')) {
            $query->open();
        }

        if ($request->filled('kind')) {
            $query->ofKind(explode(',', $request->string('kind')->toString()));
        }

        if ($request->filled('priority')) {
            $query->whereIn('priority', explode(',', $request->string('priority')->toString()));
        }

        if ($request->filled('property_id')) {
            $query->where('property_id', $request->string('property_id')->toString());
        }

        if ($request->filled('reservation_id')) {
            $query->where('reservation_id', $request->string('reservation_id')->toString());
        }

        if ($request->filled('assigned_to_id')) {
            $query->where('assigned_to_id', $request->string('assigned_to_id')->toString());
        }

        if ($request->filled('team_id')) {
            $query->where('team_id', $request->string('team_id')->toString());
        }

        if ($request->boolean('unassigned')) {
            $query->unassigned();
        }

        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        if ($request->filled('from') && $request->filled('to')) {
            $query->scheduledBetween(
                $request->string('from')->toString(),
                $request->string('to')->toString(),
            );
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';

            $query->where(function ($q) use ($term): void {
                $q->where('title', 'ilike', $term)
                    ->orWhere('reference', 'ilike', $term)
                    ->orWhere('description', 'ilike', $term);
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Task $task = null): array
    {
        $creating = $task === null;

        return [
            'kind' => [$creating ? 'required' : 'sometimes', Rule::enum(TaskKind::class)],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'property_id' => [$creating ? 'required' : 'sometimes', 'string', 'exists:properties,id'],
            'unit_id' => ['sometimes', 'nullable', 'string', 'exists:units,id'],
            'reservation_id' => ['sometimes', 'nullable', 'string', 'exists:reservations,id'],

            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'assigned_to_id' => ['sometimes', 'nullable', 'string', 'exists:users,id'],
            'team_id' => ['sometimes', 'nullable', 'string', 'exists:teams,id'],
            'vendor_id' => ['sometimes', 'nullable', 'string', 'exists:vendors,id'],

            'scheduled_start' => ['sometimes', 'nullable', 'date'],
            'scheduled_end' => ['sometimes', 'nullable', 'date', 'after_or_equal:scheduled_start'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'estimated_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],

            'estimated_cost' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'billable_to' => ['sometimes', 'nullable', Rule::in(['owner', 'guest', 'manager'])],

            'issue_category' => ['sometimes', 'nullable', 'string', 'max:48'],
            'severity' => ['sometimes', 'nullable', Rule::in(['minor', 'moderate', 'major', 'critical'])],
            'affects_availability' => ['sometimes', 'boolean'],

            'checklist_template_id' => ['sometimes', 'nullable', 'string', 'exists:checklist_templates,id'],
            'checklist_items' => ['sometimes', 'array'],
            'checklist_items.*.label' => ['required', 'string', 'max:255'],
            'checklist_items.*.section' => ['sometimes', 'nullable', 'string', 'max:120'],
            'checklist_items.*.requires_photo' => ['sometimes', 'boolean'],
        ];
    }
}
