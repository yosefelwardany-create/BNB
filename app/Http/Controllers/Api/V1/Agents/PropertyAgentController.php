<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agents;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Exceptions\BotEndpointRefusedException;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Services\AgentEvaluator;
use App\Domain\Agents\Services\EvalScenarioSet;
use App\Domain\Agents\Services\GuestAgent;
use App\Domain\Integrations\Contracts\PerPropertyAIProvider;
use App\Domain\Integrations\DataObjects\AIMessageContext;
use App\Domain\Integrations\Exceptions\AIProviderUnavailableException;
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
    /**
     * Put to a bot when testing the wire rather than the answer.
     *
     * Dull on purpose. A real guest question would make the quality of the reply
     * part of what is being judged, and this is only asking whether the endpoint
     * answers at all.
     */
    private const TEST_QUESTION = 'This is a connection test from Habitat. Reply with one short sentence confirming you can hear it.';

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
            'capabilities' => $this->capabilities($property),
        ]]);
    }

    public function update(UpdatePropertyAgentRequest $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $brief = $this->briefs->save($property, $request->briefChanges());

        return response()->json(['data' => [
            'property_id' => $property->getKey(),
            'brief' => $brief->toArray(),
            'capabilities' => $this->capabilities($property),
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
            'provider' => $this->capabilities($property)['provider'],
            'was_sent' => false,
        ]]);
    }

    /**
     * Does this property's bot answer, and what does it say?
     *
     * Its own endpoint rather than a corner of `ask`, because when six bots are
     * being wired up the useful question is not "did the agent produce a draft"
     * but "which half is broken". `ask` runs the whole pipeline — the facts, the
     * classification, the draft, four gates — so a failure anywhere in it reads
     * the same. This does one thing: puts a fixed question to the endpoint and
     * reports what came back, including how Habitat read it.
     *
     * Sends nothing to anybody. The question is Habitat's, not a guest's, and it
     * is deliberately dull: a real question would make the answer's quality part
     * of what is being tested, and the only thing being tested here is the wire.
     */
    public function testBot(Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $brief = $this->briefs->for($property);

        if ($brief->provider !== 'bot') {
            return response()->json([
                'message' => 'This property is not set to use its own bot, so there is no endpoint to test.',
            ], 422);
        }

        $provider = $this->providers->make('bot');

        if (! $provider instanceof PerPropertyAIProvider) {
            return response()->json(['message' => 'The bot provider is not configured on this deployment.'], 422);
        }

        $bot = $provider->forProperty($property);

        $context = new AIMessageContext(
            messages: [['role' => 'guest', 'body' => self::TEST_QUESTION]],
            // Enough for the bot to have something to answer from, and nothing
            // this test needs to be entitled to.
            property: ['name' => $property->name, 'city' => $property->city],
        );

        try {
            $completion = $bot->draftReply($context, 'Answer in one short sentence.');
        } catch (AIProviderUnavailableException|BotEndpointRefusedException $e) {
            // The bot's own words, or the guard's. Either is the useful thing on
            // the screen of somebody wondering why their bot went quiet.
            return response()->json([
                'data' => [
                    'reached' => false,
                    'bot' => $brief->botName,
                    'endpoint' => $brief->botUrl,
                    'problem' => $e->getMessage(),
                    'token_sent' => $property->agent_bot_token !== null,
                ],
            ]);
        }

        $classification = $bot->classify($context);

        return response()->json(['data' => [
            'reached' => true,
            'bot' => $brief->botName ?? $completion->model,
            'endpoint' => $brief->botUrl,
            'token_sent' => $property->agent_bot_token !== null,
            'asked' => self::TEST_QUESTION,
            'reply' => $completion->text,
            // What Habitat made of it, which is what decides whether a draft
            // waits for a person. A bot answering well but reporting no
            // confidence is working and will never auto-send, and that is a
            // different thing from being broken.
            'read_as' => [
                'intent' => $classification->intent,
                'confidence' => $classification->confidence,
                'stated_confidence' => $classification->confidence > 0.0,
            ],
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
    private function capabilities(Property $property): array
    {
        $brief = $this->briefs->for($property);

        $provider = $brief->provider !== null && $this->providers->has($brief->provider)
            ? $this->providers->make($brief->provider)
            : $this->providers->default();

        // Configured per property, so the screen must report the one actually
        // answering for *this* one — not the account's default, which is what it
        // used to send and which would have said "Claude" over a property whose
        // own bot was doing the work.
        if ($provider instanceof PerPropertyAIProvider) {
            $provider = $provider->forProperty($property);
        }

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
                'is_property_default' => $brief->provider === null,
            ],
            // What this property could be switched to, with the account's own
            // choice named so the screen can say what "default" means here.
            'providers' => array_values(array_map(
                fn (string $key): array => [
                    'key' => $key,
                    'name' => $this->providers->make($key)->displayName(),
                ],
                $this->providers->keys(),
            )),
            'account_provider' => $this->providers->default()->key(),
            // Whether a bot token is stored, never the token. A screen has to be
            // able to say "a token is set" without being able to show it.
            'bot_token_set' => $property->agent_bot_token !== null,
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
