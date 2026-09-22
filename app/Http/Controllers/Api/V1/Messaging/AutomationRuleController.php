<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Domain\Automation\Models\AutomationRule;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Services\ActionRunner;
use App\Domain\Automation\Services\ConditionEvaluator;
use App\Domain\Automation\Services\ScheduledAutomationPlanner;
use App\Domain\Reservations\Events\ReservationPayload;
use App\Domain\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Resources\AutomationRuleResource;
use App\Http\Resources\AutomationRunResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Automation rules and the log of what they did.
 *
 * Two things here are as important as the CRUD.
 *
 * `vocabulary` publishes the complete list of triggers, operators and actions.
 * Rules are data evaluated by code with a fixed vocabulary — nothing a
 * customer writes is ever executed — so the vocabulary *is* the contract, and
 * an editor that offers anything else is offering something that will fail
 * closed.
 *
 * `test` evaluates a rule against a real reservation without doing anything.
 * A rule that can message every guest in the portfolio should be observable
 * before it is switched on.
 */
class AutomationRuleController extends Controller
{
    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly ScheduledAutomationPlanner $planner,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AutomationRule::class);

        $query = AutomationRule::query();

        if ($request->boolean('active_only')) {
            $query->active();
        }

        if ($request->filled('trigger_type')) {
            $query->where('trigger_type', $request->string('trigger_type')->toString());
        }

        return AutomationRuleResource::collection(
            $query->orderBy('name')->paginate($this->perPage()),
        );
    }

    /**
     * Everything a rule may be built from.
     */
    public function vocabulary(): JsonResponse
    {
        $this->authorize('viewAny', AutomationRule::class);

        return response()->json([
            'triggers' => [
                'event' => [
                    'label' => 'When something happens',
                    'events' => $this->supportedEvents(),
                ],
                'schedule' => [
                    'label' => 'At a time relative to a booking',
                    'anchors' => [
                        AutomationRule::ANCHOR_CHECK_IN => 'Check-in',
                        AutomationRule::ANCHOR_CHECK_OUT => 'Check-out',
                        AutomationRule::ANCHOR_BOOKED_AT => 'When the booking was made',
                    ],
                    // Said explicitly because it is the single most
                    // consequential detail of the whole feature.
                    'note' => "Offsets and times of day are evaluated in each property's own timezone.",
                ],
            ],
            'operators' => ConditionEvaluator::operators(),
            'actions' => ActionRunner::vocabulary(),
            'fields' => $this->conditionFields(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AutomationRule::class);

        $data = $request->validate($this->rules());

        $this->assertRuleIsCoherent($data);

        $rule = new AutomationRule;
        $rule->fill($data);
        $rule->organization_id = $this->organization()->getKey();
        $rule->created_by_id = $this->currentUser()->getKey();
        $rule->save();

        return (new AutomationRuleResource($rule))->response()->setStatusCode(201);
    }

    public function show(AutomationRule $automationRule): AutomationRuleResource
    {
        $this->authorize('view', $automationRule);

        return new AutomationRuleResource($automationRule);
    }

    public function update(Request $request, AutomationRule $automationRule): AutomationRuleResource
    {
        $this->authorize('update', $automationRule);

        $data = $request->validate($this->rules($automationRule));

        $this->assertRuleIsCoherent(array_merge($automationRule->only([
            'trigger_type', 'trigger_event', 'trigger_anchor', 'conditions', 'actions',
        ]), $data));

        $automationRule->fill($data)->save();

        return new AutomationRuleResource($automationRule->fresh());
    }

    /**
     * What the rule did, most recent first.
     */
    public function runs(Request $request, AutomationRule $automationRule): AnonymousResourceCollection
    {
        $this->authorize('view', $automationRule);

        $query = $automationRule->runs()->getQuery();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return AutomationRunResource::collection($query->paginate($this->perPage()));
    }

    /**
     * Evaluate the rule against one reservation, changing nothing.
     *
     * Returns the same condition trace a real run would record, so the answer
     * to "would this have fired?" is produced by the code that decides it
     * rather than by a second implementation that can drift.
     */
    public function test(Request $request, AutomationRule $automationRule): JsonResponse
    {
        $this->authorize('view', $automationRule);

        $data = $request->validate([
            'reservation_id' => ['required', 'string', 'exists:reservations,id'],
        ]);

        $reservation = Reservation::query()
            ->with(['property', 'guest'])
            ->findOrFail($data['reservation_id']);

        $payload = ReservationPayload::build($reservation);
        $evaluation = $this->conditions->evaluate($automationRule->conditions, $payload);

        $scopeReasons = [];

        if (! $automationRule->appliesToProperty($reservation->property_id)) {
            $scopeReasons[] = 'This rule is limited to other properties.';
        }

        if (! $automationRule->appliesToChannel($reservation->source)) {
            $scopeReasons[] = sprintf('This rule does not apply to bookings from %s.', $reservation->source);
        }

        return response()->json([
            'would_run' => $evaluation['passed'] && $scopeReasons === [],
            'conditions' => $evaluation['trace'],
            'excluded_because' => $scopeReasons,
            'would_fire_at' => $automationRule->trigger_type === AutomationRule::TRIGGER_SCHEDULE
                ? $this->planner->firingMoment($automationRule, $reservation)?->toIso8601String()
                : null,
            'actions' => $automationRule->actions,
        ]);
    }

    /**
     * Switch a rule off.
     *
     * Deactivated rather than deleted where it has run: every run references
     * the rule, and the run log is the only answer to what a guest was sent
     * and why.
     */
    public function destroy(AutomationRule $automationRule): JsonResponse
    {
        $this->authorize('delete', $automationRule);

        if ($automationRule->runs()->exists()) {
            $automationRule->forceFill(['is_active' => false])->save();

            return response()->json([
                'message' => 'The rule has been switched off. Its run history is kept so past messages remain explainable.',
                'deactivated' => true,
            ]);
        }

        $automationRule->delete();

        return response()->json(['message' => 'The rule has been deleted.', 'deactivated' => false]);
    }

    /**
     * Every automation run in the organization, for the activity screen.
     */
    public function allRuns(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AutomationRule::class);

        $query = AutomationRun::query()->with('rule');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('rule_id')) {
            $query->where('automation_rule_id', $request->string('rule_id')->toString());
        }

        return AutomationRunResource::collection(
            $query->orderByDesc('created_at')->paginate($this->perPage()),
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Refuse a rule that cannot work, with a reason a person can act on.
     *
     * Validation rules cover the shape; this covers the sense. A rule stored
     * in a state that fails closed at runtime is worse than one refused at
     * the point somebody wrote it, because nobody finds out until a guest
     * does not get their arrival instructions.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertRuleIsCoherent(array $data): void
    {
        $errors = [];

        $triggerType = $data['trigger_type'] ?? AutomationRule::TRIGGER_EVENT;

        if ($triggerType === AutomationRule::TRIGGER_EVENT && blank($data['trigger_event'] ?? null)) {
            $errors['trigger_event'] = 'An event rule needs an event to react to.';
        }

        if ($triggerType === AutomationRule::TRIGGER_SCHEDULE && blank($data['trigger_anchor'] ?? null)) {
            $errors['trigger_anchor'] = 'A scheduled rule needs a date to be relative to.';
        }

        foreach ((array) ($data['actions'] ?? []) as $index => $action) {
            $type = $action['type'] ?? null;

            if (! in_array($type, ActionRunner::availableActions(), true)) {
                $errors["actions.{$index}.type"] = sprintf(
                    'Unknown action [%s]. Available: %s.',
                    (string) $type,
                    implode(', ', ActionRunner::availableActions()),
                );

                continue;
            }

            // An action missing its one required parameter would fail on every
            // run, silently, for every guest it was meant to reach.
            $required = match ($type) {
                'send_message' => 'template_id',
                'add_internal_note' => 'body',
                'create_task' => 'title',
                'notify_staff' => 'title',
                'tag_reservation' => 'tags',
                default => null,
            };

            if ($required !== null && blank($action['parameters'][$required] ?? null)) {
                $errors["actions.{$index}.parameters.{$required}"] = sprintf(
                    'The "%s" action needs a %s.',
                    $type,
                    str_replace('_', ' ', $required),
                );
            }
        }

        if (isset($data['conditions']) && is_array($data['conditions'])) {
            foreach ($this->conditions->validate($data['conditions']) as $index => $message) {
                $errors["conditions.{$index}"] = $message;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The events a rule may react to.
     *
     * A curated list rather than everything in the store: an operator choosing
     * a trigger should see the things that happen in their business, not the
     * platform's internal bookkeeping.
     *
     * @return array<string, string>
     */
    private function supportedEvents(): array
    {
        return [
            'reservation.created' => 'A booking is created',
            'reservation.confirmed' => 'A booking is confirmed',
            'reservation.modified' => 'A booking is changed',
            'reservation.cancelled' => 'A booking is cancelled',
            'guest.checked_in' => 'A guest checks in',
            'guest.checked_out' => 'A guest checks out',
            'message.received' => 'A guest writes to us',
            'task.created' => 'A task is created',
            'task.completed' => 'A task is completed',
        ];
    }

    /**
     * Fields a condition may test, grouped for the editor.
     *
     * @return array<string, array<string, string>>
     */
    private function conditionFields(): array
    {
        return [
            'Booking' => [
                'status' => 'Booking status',
                'source' => 'Where the booking came from',
                'nights' => 'Number of nights',
                'adults' => 'Number of adults',
                'children' => 'Number of children',
                'total_guests' => 'Total guests',
                'grand_total.amount' => 'Total value',
                'balance_due.amount' => 'Outstanding balance',
                'lead_time_days' => 'Days booked in advance',
            ],
            'Property' => [
                'property_id' => 'Property',
                'unit_id' => 'Unit',
            ],
            'Guest' => [
                'guest_id' => 'Guest',
            ],
            'Message' => [
                'direction' => 'Inbound or outbound',
                'body' => 'Message text',
                'transport' => 'How it arrived',
            ],
            'Task' => [
                'kind' => 'Kind of work',
                'priority' => 'Priority',
                'is_overdue' => 'Overdue',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?AutomationRule $rule = null): array
    {
        $creating = $rule === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],

            'trigger_type' => [
                $creating ? 'required' : 'sometimes',
                Rule::in([AutomationRule::TRIGGER_EVENT, AutomationRule::TRIGGER_SCHEDULE]),
            ],
            'trigger_event' => ['sometimes', 'nullable', 'string', 'max:128'],
            'trigger_anchor' => [
                'sometimes', 'nullable',
                Rule::in([
                    AutomationRule::ANCHOR_CHECK_IN,
                    AutomationRule::ANCHOR_CHECK_OUT,
                    AutomationRule::ANCHOR_BOOKED_AT,
                ]),
            ],
            // A year either side: enough for "a year after the stay, ask them
            // back", bounded enough that a typo cannot schedule into 2308.
            'trigger_offset_minutes' => ['sometimes', 'nullable', 'integer', 'min:-525600', 'max:525600'],
            'trigger_time_of_day' => ['sometimes', 'nullable', 'date_format:H:i'],

            'conditions' => ['sometimes', 'nullable', 'array'],
            'actions' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'actions.*.type' => ['required', 'string'],
            'actions.*.parameters' => ['sometimes', 'array'],

            'delay_minutes' => ['sometimes', 'integer', 'min:0', 'max:43200'],
            'property_ids' => ['sometimes', 'nullable', 'array'],
            'property_ids.*' => ['string', 'exists:properties,id'],
            'channels' => ['sometimes', 'nullable', 'array'],
            'channels.*' => ['string', 'max:48'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
