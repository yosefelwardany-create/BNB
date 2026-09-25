<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agents;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Services\AgentEvaluator;
use App\Domain\Agents\Services\EvalScenarioSet;
use App\Domain\Agents\Services\GuestAgent;
use App\Domain\Integrations\Registries\AIProviderRegistry;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Properties\UpdatePropertyAgentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Configuring one property's guest agent, and testing it without sending.
 *
 * Every action here is read-or-configure. Nothing in this controller delivers a
 * message to a guest: `ask` and `evaluate` produce drafts and scores, and the
 * response says so. That is what makes the bench safe to point at a live
 * property with real bookings — which is also the only place the agent's answers
 * mean anything, because a fixture's facts are tidier than a real portfolio's.
 */
class PropertyAgentController extends Controller
{
    public function __construct(
        private readonly AgentBriefStore $briefs,
        private readonly GuestAgent $agent,
        private readonly AgentEvaluator $evaluator,
        private readonly EvalScenarioSet $scenarios,
        private readonly AIProviderRegistry $providers,
    ) {}

    /**
     * The brief in force, plus what may be changed about it.
     */
    public function show(Property $property): JsonResponse
    {
        $this->authorize('view', $property);

        return response()->json(['data' => [
            'property_id' => $property->getKey(),
            'brief' => $this->briefs->for($property)->toArray(),
            'capabilities' => $this->capabilities(),
        ]]);
    }

    public function update(UpdatePropertyAgentRequest $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $brief = $this->briefs->save($property, $request->briefChanges());

        return response()->json(['data' => [
            'property_id' => $property->getKey(),
            'brief' => $brief->toArray(),
            'capabilities' => $this->capabilities(),
        ]]);
    }

    /**
     * Ask the agent a question and get the draft it would produce.
     *
     * Sends nothing, which is the point: an operator can put the awkward
     * question to a live property's agent before a guest does.
     */
    public function ask(Request $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:2', 'max:2000'],
            'reservation_id' => ['sometimes', 'nullable', 'string'],
            'guest_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'history' => ['sometimes', 'array', 'max:20'],
            'history.*.role' => ['required', 'in:guest,host'],
            'history.*.body' => ['required', 'string', 'max:2000'],
        ]);

        $reservation = $this->reservation($property, $validated['reservation_id'] ?? null);

        $answer = $this->agent->answer(
            property: $property,
            question: $validated['question'],
            reservation: $reservation,
            history: array_values($validated['history'] ?? []),
            guestName: $validated['guest_name'] ?? $reservation?->guest?->fullName(),
        );

        return response()->json(['data' => [
            'answer' => $answer->toArray(),
            'reservation' => $reservation === null ? null : [
                'id' => $reservation->getKey(),
                'confirmation_code' => $reservation->confirmation_code,
            ],
            // Stated in the payload and not only in the docs, so a client
            // cannot render this as a sent reply by accident.
            'was_sent' => false,
        ]]);
    }

    /**
     * Score the agent against a set of scenarios whose answers are known.
     *
     * The same evaluator and the same scenario files as `php artisan
     * agent:evaluate`, so the number in the browser is the number in CI.
     */
    public function evaluate(Request $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $validated = $request->validate([
            'set' => ['sometimes', 'string', 'max:80'],
        ]);

        try {
            $scenarios = $this->scenarios->load($validated['set'] ?? 'guest-questions');
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'available_sets' => EvalScenarioSet::available(),
            ], 422);
        }

        $outcome = $this->evaluator->runAll($scenarios, $this->scenarios->resolver($property));

        return response()->json(['data' => [
            ...$outcome,
            'set' => $validated['set'] ?? 'guest-questions',
            'available_sets' => EvalScenarioSet::available(),
            'provider' => $this->capabilities()['provider'],
            'was_sent' => false,
        ]]);
    }

    /**
     * What any client needs in order to render the brief honestly.
     *
     * The provider block is here rather than inferred client-side because a
     * bench showing green answers from the local simulation, with nothing
     * saying so, is the exact misleading thing this platform refuses to build.
     *
     * @return array<string, mixed>
     */
    private function capabilities(): array
    {
        $provider = $this->providers->default();

        return [
            'intents' => AgentBrief::intents(),
            // A fixed list, not a setting. Worth sending so the UI can explain
            // why "payment" is not offered as an auto-send option.
            'auto_sendable' => AgentBrief::AUTO_SENDABLE,
            'provider' => [
                'key' => $provider->key(),
                'name' => $provider->displayName(),
                'is_live' => $provider->isLive(),
                'simulation_reason' => $provider->isLive() ? null : $provider->simulationReason(),
            ],
        ];
    }

    private function reservation(Property $property, ?string $id): ?Reservation
    {
        if ($id === null || $id === '') {
            return null;
        }

        // Scoped to the property as well as the tenant: the entitlement rules
        // are about this property's arrival details, and a booking at another
        // property must not unlock them.
        return Reservation::query()
            ->where('property_id', $property->getKey())
            ->with(['property', 'guest'])
            ->whereKey($id)
            ->firstOrFail();
    }
}
