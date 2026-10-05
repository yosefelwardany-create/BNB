<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agents;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Enums\AgentAudience;
use App\Domain\Agents\Enums\AgentCapability;
use App\Domain\Agents\Exceptions\AgentNotConfiguredException;
use App\Domain\Agents\Exceptions\BotEndpointRefusedException;
use App\Domain\Agents\Models\AgentAction;
use App\Domain\Agents\Models\AgentActivity;
use App\Domain\Agents\Models\AgentAsk;
use App\Domain\Agents\Services\AgentActions;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Services\AgentEvaluator;
use App\Domain\Agents\Services\DeferredAgent;
use App\Domain\Agents\Services\EvalScenarioSet;
use App\Domain\Agents\Services\GuestAgent;
use App\Domain\Agents\Services\PropertyAgentMemory;
use App\Domain\Integrations\Contracts\PerPropertyAIProvider;
use App\Domain\Integrations\DataObjects\AIMessageContext;
use App\Domain\Integrations\Exceptions\AIProviderUnavailableException;
use App\Domain\Integrations\Registries\AIProviderRegistry;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyDocument;
use App\Domain\Properties\Services\KnowledgeDocumentFetcher;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Services\AccessControl;
use App\Http\Controllers\Controller;
use App\Http\Requests\Properties\UpdatePropertyAgentRequest;
use App\Http\Resources\AgentActionResource;
use App\Http\Resources\AgentActivityResource;
use App\Http\Resources\AgentAskResource;
use App\Http\Resources\PropertyDocumentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        private readonly DeferredAgent $deferred,
        private readonly AgentActions $actions,
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

        if ($request->boolean('automatic_guest_replies')) {
            abort_unless(app(AccessControl::class)->allows($request->user(), 'messages.send', $property->organization_id), 403);
        }

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
            // Who the question is on behalf of, which decides the whole body of
            // facts the agent is given. Defaults to the guest, which is what
            // this endpoint has always meant.
            'audience' => ['sometimes', 'string', 'in:guest,operator'],
        ]);

        $audience = AgentAudience::from($validated['audience'] ?? 'guest');
        $reservation = $this->reservation($property, $validated['reservation_id'] ?? null);

        if ($audience === AgentAudience::Operator) {
            app(PropertyAgentMemory::class)->remember($property, $request->user(), $validated['question']);
        }

        $answer = $this->agent->answer(
            property: $property,
            question: $validated['question'],
            reservation: $reservation,
            history: array_values($validated['history'] ?? []),
            guestName: $validated['guest_name'] ?? $reservation?->guest?->fullName(),
            audience: $audience,
            asker: $request->user(),
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
     * Ask the agent something it will answer later, and return straight away.
     *
     * The counterpart to `ask`, for a bot too slow to hold a request open. This
     * writes the question down, fires the property's webhook and returns a row
     * that says "waiting" — the answer lands on {@see AgentCallbackController}
     * whenever the bot is finished, and the screen picks it up from `asks`.
     *
     * Sends nothing to anybody, like everything else in this controller.
     */
    public function askLater(Request $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:2', 'max:2000'],
            'reservation_id' => ['sometimes', 'nullable', 'string'],
            'guest_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'history' => ['sometimes', 'array', 'max:20'],
            'history.*.role' => ['required', 'in:guest,host'],
            'history.*.body' => ['required', 'string', 'max:2000'],
            'audience' => ['sometimes', 'string', 'in:guest,operator'],
        ]);

        $reservation = $this->reservation($property, $validated['reservation_id'] ?? null);

        $ask = $this->deferred->ask(
            property: $property,
            question: $validated['question'],
            reservation: $reservation,
            history: array_values($validated['history'] ?? []),
            guestName: $validated['guest_name'] ?? $reservation?->guest?->fullName(),
            asker: $request->user(),
            audience: AgentAudience::from($validated['audience'] ?? 'guest'),
        );

        if (($validated['audience'] ?? 'guest') === 'operator') {
            app(PropertyAgentMemory::class)->remember($property, $request->user(), $validated['question']);
        }

        return response()->json(['data' => new AgentAskResource($ask)], 202);
    }

    public function memories(Request $request, Property $property, PropertyAgentMemory $memory): JsonResponse
    {
        $this->authorize('update', $property);
        $query = $memory->query($property, $request->user());
        $search = $request->validate(['search' => ['sometimes', 'string', 'max:200']])['search'] ?? '';
        if ($search !== '') {
            $query->where('content', 'like', '%'.addcslashes($search, '%_\\').'%');
        }

        return response()->json($query->latest('id')->paginate(30, ['id', 'content', 'created_at']));
    }

    public function forgetMemory(Request $request, Property $property, string $memory, PropertyAgentMemory $store): JsonResponse
    {
        $this->authorize('update', $property);
        $store->query($property, $request->user())->whereKey($memory)->firstOrFail()->delete();

        return response()->json(['data' => ['forgotten' => true]]);
    }

    /**
     * This property's recent asks, newest first.
     *
     * What the screen polls while it waits. Deliberately the whole recent list
     * rather than only the pending one: an operator who asked three questions
     * wants to see all three settle, and a page that showed only the newest
     * would lose the answer to the second one the moment they asked a third.
     */
    public function asks(Request $request, Property $property): JsonResponse
    {
        $this->authorize('view', $property);

        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $asks = AgentAsk::query()
            ->where('property_id', $property->getKey())
            ->orderByDesc('created_at')
            ->limit($validated['limit'] ?? 20)
            ->get();

        return response()->json([
            'data' => AgentAskResource::collection($asks),
            // So the screen can say how long a pending ask has left without
            // hard-coding a number that configuration can change.
            'meta' => ['window_minutes' => $this->deferred->window()],
        ]);
    }

    /**
     * What this property's agent has done, newest first.
     *
     * The log the Oct 1 review asked for: every autonomous action, so somebody
     * can check afterwards what happened while they were not watching, and so a
     * reply that went out on its own is findable rather than inferred from a
     * guest's confusion three days later.
     *
     * `autonomous=1` narrows it to exactly that — the things nobody read first.
     */
    public function activity(Request $request, Property $property): JsonResponse
    {
        $this->authorize('view', $property);

        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'autonomous' => ['sometimes', 'boolean'],
        ]);

        $activity = AgentActivity::query()
            ->where('property_id', $property->getKey())
            ->when($validated['autonomous'] ?? false, fn ($query) => $query->autonomous())
            ->with('actor')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($validated['limit'] ?? 50)
            ->get();

        return response()->json([
            'data' => AgentActivityResource::collection($activity),
            'meta' => [
                // Counted over everything, not the page, so a screen showing
                // twenty rows can still say how many of them went out unread.
                'autonomous_count' => AgentActivity::query()
                    ->where('property_id', $property->getKey())
                    ->autonomous()
                    ->count(),
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Things the agent proposes to do
    |--------------------------------------------------------------------------
    |
    | Asking is cheap; approving is the act. So `actions` and `propose` need only
    | the property's own permissions, and `approve` needs whatever the capability
    | itself needs — the same permission a person would need to do it by hand.
    | Without that last rule the agent is a way around the permission system.
    |
    */

    /**
     * What this agent has proposed, newest first.
     *
     * `open=1` narrows it to what is still worth a decision, which is what the
     * approval badge counts. Not the same as `status=proposed`: a proposal past
     * its expiry is still proposed until something sweeps it, and offering a
     * button on it would let somebody approve a situation that has moved on.
     */
    public function actions(Request $request, Property $property): JsonResponse
    {
        $this->authorize('view', $property);

        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'open' => ['sometimes', 'boolean'],
        ]);

        $query = AgentAction::query()
            ->where('property_id', $property->getKey())
            ->with(['requestedBy', 'approvedBy'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($validated['open'] ?? false) {
            $query->awaitingApproval()->where(
                fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()),
            );
        }

        return response()->json([
            'data' => AgentActionResource::collection($query->limit($validated['limit'] ?? 50)->get()),
            'meta' => [
                // Counted over everything rather than the page, so a screen
                // showing twenty rows can still badge how many are waiting.
                'waiting' => AgentAction::query()
                    ->where('property_id', $property->getKey())
                    ->awaitingApproval()
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->count(),
            ],
        ]);
    }

    /**
     * Ask the agent to do something.
     *
     * The honest shape of "ask the agent to handle it": a request that becomes a
     * proposal, and runs now only where the property's brief says that capability
     * may run unattended. The response says which happened — `status` is
     * `executed` when it ran and `proposed` when it is waiting — rather than
     * reporting success either way.
     */
    public function propose(Request $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $validated = $request->validate([
            'capability' => ['required', 'string', Rule::in(array_map(
                static fn (AgentCapability $capability): string => $capability->value,
                AgentCapability::all(),
            ))],
            'summary' => ['required', 'string', 'max:480'],
            'arguments' => ['sometimes', 'array'],
        ]);

        $capability = AgentCapability::from($validated['capability']);

        /*
         * The proposer needs the capability's permission too.
         *
         * Not only the approver. A proposal is visible to everybody who can see
         * the property and carries the agent's wording, so somebody with no
         * authority over bookings could otherwise park "cancel this, the guest
         * agreed" in front of a colleague who does. Checked here as well as at
         * approval, and both are load-bearing.
         */
        $this->authorizeCapability($request, $capability);

        try {
            $action = $this->actions->propose(
                $property,
                $capability,
                $validated['arguments'] ?? [],
                $validated['summary'],
                $request->user(),
            );
        } catch (AgentNotConfiguredException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(
            ['data' => new AgentActionResource($action->fresh(['requestedBy', 'approvedBy']))],
            201,
        );
    }

    /**
     * Yes, do it.
     *
     * Runs synchronously and reports what the channel said, including when it
     * refused: an approval that answered "queued" would leave somebody believing
     * a message reached a guest when a token had expired.
     */
    public function approveAction(Request $request, Property $property, AgentAction $action): JsonResponse
    {
        $this->authorize('update', $property);
        $this->belongsToProperty($property, $action);
        $this->authorizeCapability($request, $action->capability);

        try {
            $action = $this->actions->approve($action, $request->user());
        } catch (AgentNotConfiguredException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new AgentActionResource($action->fresh(['requestedBy', 'approvedBy'])),
        ]);
    }

    public function rejectAction(Request $request, Property $property, AgentAction $action): JsonResponse
    {
        $this->authorize('update', $property);
        $this->belongsToProperty($property, $action);
        $this->authorizeCapability($request, $action->capability);

        $validated = $request->validate(['because' => ['sometimes', 'nullable', 'string', 'max:500']]);

        try {
            $action = $this->actions->reject($action, $request->user(), $validated['because'] ?? null);
        } catch (AgentNotConfiguredException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new AgentActionResource($action->fresh(['requestedBy', 'approvedBy'])),
        ]);
    }

    /**
     * Whoever is deciding needs the authority to do it by hand.
     */
    private function authorizeCapability(Request $request, AgentCapability $capability): void
    {
        $user = $request->user();

        if ($user === null || ! app(AccessControl::class)->allows($user, $capability->permission())) {
            abort(403, sprintf(
                'Approving this needs the same permission as doing it by hand (%s).',
                $capability->permission(),
            ));
        }
    }

    /**
     * The action must be this property's.
     *
     * Route binding already scopes it to the tenant, and this is the other half:
     * a 404 rather than a 403, because an action at a property this caller may
     * not see should not be confirmed to exist by the shape of the refusal.
     */
    private function belongsToProperty(Property $property, AgentAction $action): void
    {
        abort_if($action->property_id !== $property->getKey(), 404);
    }

    /**
     * The documents this property's agent reads, and whether it can.
     */
    public function documents(Property $property): JsonResponse
    {
        $this->authorize('view', $property);

        return response()->json([
            'data' => PropertyDocumentResource::collection(
                $property->documents()->with('property')->get(),
            ),
        ]);
    }

    /**
     * Read the document again, now.
     *
     * Its own action because a knowledge base is edited and then immediately
     * tested: waiting for the hourly refresh to find out whether a correction
     * took is how somebody concludes the feature does not work.
     */
    public function refreshDocument(Property $property, PropertyDocument $document, KnowledgeDocumentFetcher $fetcher): JsonResponse
    {
        $this->authorize('update', $property);

        abort_unless($document->property_id === $property->getKey(), 404);

        return response()->json([
            'data' => new PropertyDocumentResource($fetcher->refresh($document)->load('property')),
        ]);
    }

    /**
     * Decide whether this document may be shown to guests.
     *
     * Separate from everything else on the agent screen, and never part of a
     * bulk save: a house manual routinely contains a door code, and turning this
     * on is the one setting here that can hand one to somebody with no booking.
     * It should take a deliberate act.
     */
    public function shareDocument(Request $request, Property $property, PropertyDocument $document): JsonResponse
    {
        $this->authorize('update', $property);

        abort_unless($document->property_id === $property->getKey(), 404);

        $validated = $request->validate(['is_guest_safe' => ['required', 'boolean']]);

        $document->forceFill(['is_guest_safe' => $validated['is_guest_safe']])->save();

        return response()->json(['data' => new PropertyDocumentResource($document->load('property'))]);
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

        // Configured per property, so the screen must report the one actually
        // answering for *this* one — not the account's default, which is what it
        // used to send and which would have said "Claude" over a property whose
        // own bot was doing the work.
        $provider = $this->providers->forProperty($property);

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

            // The slow path. Reported separately from the bot's because a
            // property can have both, and a screen that conflated them would
            // offer "ask and wait" on a property that only has a webhook.
            'webhook_set' => $brief->webhookUrl !== null,
            'webhook_token_set' => $property->agent_webhook_token !== null,
            'webhook_window_minutes' => $this->deferred->window(),
            // Who a question may be asked on behalf of. A list rather than a
            // boolean so the screen names them rather than inventing labels.
            /*
             * What the agent may be asked to *do*, with the stakes attached.
             *
             * Each entry carries its own consequence sentence and whether it may
             * ever run unattended, so the settings screen explains the choice at
             * the moment it is offered rather than listing six checkboxes that
             * all look alike. `may_ever_be_autonomous` is false for cancelling
             * and no setting changes that, which is why it is sent as a fact
             * about the capability rather than as the current value.
             */
            'actions' => array_map(
                static fn (AgentCapability $capability): array => [
                    'key' => $capability->value,
                    'label' => $capability->label(),
                    'consequence' => $capability->consequence(),
                    'defaults_to_autonomous' => $capability->defaultsToAutonomous(),
                    'may_ever_be_autonomous' => $capability->mayEverBeAutonomous(),
                    // The permission needed to approve one. Shown so an operator
                    // granting a capability can see who will be able to say yes.
                    'permission' => $capability->permission(),
                ],
                AgentCapability::all(),
            ),
            'audiences' => array_map(
                static fn (AgentAudience $audience): array => [
                    'key' => $audience->value,
                    'label' => $audience->label(),
                ],
                AgentAudience::cases(),
            ),
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
