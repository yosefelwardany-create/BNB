<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\DataObjects\AgentAnswer;
use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Integrations\DataObjects\AIMessageContext;
use App\Domain\Integrations\Registries\AIProviderRegistry;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;

/**
 * The agent that answers a guest's question about one property.
 *
 * The order of operations is the design. Classify first, then decide what the
 * agent is allowed to know, then draft — because the safe answer to "what's the
 * door code" is not a carefully worded refusal, it is a draft written by a model
 * that was never shown the code.
 *
 * Four gates stand between a guest's question and an answer leaving the
 * building, and each catches something the others cannot:
 *
 *  1. **Entitlement** — {@see PropertyKnowledge} decides which facts go into
 *     the prompt at all. A model cannot leak what it was not given, and no
 *     amount of persuasion in the guest's message changes what was assembled
 *     before the message was read.
 *  2. **The property's own escalation list** — a keyword match on the guest's
 *     words, checked in code rather than asked of the model. "Send anything
 *     about the neighbours to a person" has to hold even when the model
 *     disagrees about whether this counts.
 *  3. **Intent** — only four categories are ever auto-sendable, and that list
 *     is a constant, not a setting. Nothing a customer types into their own
 *     configuration can make a refund question answer itself.
 *  4. **Confidence** — an agent that is unsure and sends anyway is worse than
 *     one that waits, because the guest acts on the answer either way.
 *
 * Failing any gate does not discard the draft. It holds it, with the reason
 * recorded, which is the difference between a system somebody can improve and
 * one they learn to distrust.
 */
class GuestAgent
{
    public function __construct(
        private readonly AIProviderRegistry $providers,
        private readonly PropertyKnowledge $knowledge,
    ) {}

    /**
     * Answer one question about one property.
     *
     * Takes a question rather than a conversation so the bench and the eval
     * suite exercise exactly the same path as a live message — a second code
     * path for testing would be a second thing to be wrong.
     *
     * @param  list<array{role: string, body: string}>  $history
     */
    public function answer(
        Property $property,
        string $question,
        ?Reservation $reservation = null,
        array $history = [],
        ?string $guestName = null,
    ): AgentAnswer {
        $brief = AgentBrief::fromSettings($property->settings);
        $provider = $this->providers->default();

        // Assembled before the question is looked at, so nothing in the
        // question can influence what the agent is permitted to know.
        $facts = $this->knowledge->public($property);
        [$arrival, $withheld] = $this->knowledge->arrival($property, $reservation);
        $stay = $this->knowledge->stay($reservation);

        $context = new AIMessageContext(
            messages: [...$history, ['role' => 'guest', 'body' => $question]],
            reservation: $stay,
            property: $facts + ($arrival === [] ? [] : ['arrival' => $arrival]),
            guestName: $guestName,
            guestLanguage: $brief->languages[0] ?? 'en',
            organizationVoice: $this->voice($brief, $withheld),
        );

        $classification = $provider->classify($context);
        $intent = $this->normaliseIntent($classification->intent);

        $completion = $provider->draftReply($context, $this->instruction($brief, $intent, $withheld));

        $held = $this->reasonToHold($brief, $intent, $question, $classification->confidence, $withheld);

        return new AgentAnswer(
            reply: trim($completion->text),
            intent: $intent,
            confidence: $classification->confidence,
            wouldAutoSend: $held === null,
            heldBecause: $held,
            withheld: $withheld,
            usedFacts: array_keys($facts + ($arrival === [] ? [] : ['arrival' => $arrival])),
            isSimulated: ! $provider->isLive(),
            simulationReason: $provider->isLive() ? null : $provider->simulationReason(),
            provider: $provider->key(),
            model: $completion->model,
            promptTokens: $completion->promptTokens,
            completionTokens: $completion->completionTokens,
        );
    }

    /**
     * Answer the latest guest message on a conversation.
     */
    public function answerConversation(Conversation $conversation): ?AgentAnswer
    {
        $conversation->loadMissing(['messages', 'reservation.property', 'property', 'guest']);

        $property = $conversation->property ?? $conversation->reservation?->property;

        if ($property === null) {
            return null;
        }

        $messages = $conversation->messages
            ->sortBy('created_at')
            ->reject(fn ($message): bool => (bool) $message->is_internal_note)
            ->values();

        $latest = $messages->last(fn ($message): bool => $message->direction === 'inbound');

        if ($latest === null) {
            return null;
        }

        $history = $messages
            ->reject(fn ($message): bool => $message->getKey() === $latest->getKey())
            ->map(fn ($message): array => [
                'role' => $message->direction === 'inbound' ? 'guest' : 'host',
                'body' => (string) $message->body,
            ])
            ->values()
            ->all();

        return $this->answer(
            property: $property,
            question: (string) $latest->body,
            reservation: $conversation->reservation,
            history: $history,
            guestName: $conversation->guest?->fullName(),
        );
    }

    /**
     * Why this draft is not being sent on its own, or null if it may be.
     *
     * @param  list<string>  $withheld
     */
    private function reasonToHold(
        AgentBrief $brief,
        string $intent,
        string $question,
        float $confidence,
        array $withheld,
    ): ?string {
        if (! $brief->enabled) {
            return 'This property\'s agent is not turned on.';
        }

        $escalated = $brief->escalatedBy($question);

        if ($escalated !== null) {
            return sprintf('This property escalates anything mentioning "%s".', $escalated);
        }

        if (! in_array($intent, AgentBrief::AUTO_SENDABLE, true)) {
            return sprintf('A question about %s is always read by a person first.', str_replace('_', ' ', $intent));
        }

        if (! $brief->mayAutoSend($intent)) {
            return sprintf('This property has not enabled sending %s answers on their own.', str_replace('_', ' ', $intent));
        }

        if ($confidence < $brief->confidenceFloor) {
            return sprintf(
                'The agent was %d%% sure, below this property\'s floor of %d%%.',
                (int) round($confidence * 100),
                (int) round($brief->confidenceFloor * 100),
            );
        }

        // If the agent had to refuse a fact, a person should see how it phrased
        // that. A clumsy refusal about a door code is the message most likely to
        // produce a second, angrier question.
        if ($withheld !== []) {
            return 'The agent could not share some details, so the wording is worth a look.';
        }

        return null;
    }

    /**
     * The instruction handed to the provider alongside the context.
     *
     * @param  list<string>  $withheld
     */
    private function instruction(AgentBrief $brief, string $intent, array $withheld): string
    {
        $lines = [
            'You are replying to a guest on behalf of the host.',
            $brief->persona,
            'Answer only from the facts provided. If a fact is not there, say you will check and come back — never guess a time, a price, a code or a rule.',
        ];

        if ($brief->never !== []) {
            $lines[] = 'Never do any of the following: '.implode('; ', $brief->never).'.';
        }

        if ($brief->extraKnowledge !== null) {
            $lines[] = 'Also true of this property right now: '.$brief->extraKnowledge;
        }

        if ($withheld !== []) {
            $lines[] = 'Arrival details are deliberately not available to you for this guest: '
                .implode(' ', $withheld)
                .' Say that someone will send them, and why, without apologising at length.';
        }

        $lines[] = sprintf('The question appears to be about %s.', str_replace('_', ' ', $intent));

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $withheld
     */
    private function voice(AgentBrief $brief, array $withheld): string
    {
        return $brief->persona.($withheld === [] ? '' : ' Arrival details are withheld for this guest.');
    }

    /**
     * What older providers call these categories.
     *
     * The keyword classifier in `EchoAIProvider` predates this agent and has
     * its own vocabulary. Mapping it keeps the simulated path exercising the
     * real gates instead of collapsing every question to `other` and proving
     * nothing.
     *
     * Everything here maps to a category that is *not* auto-sendable, which is
     * the safe direction for a guess.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'billing' => AgentBrief::INTENT_PAYMENT,
        'maintenance' => AgentBrief::INTENT_COMPLAINT,
        'cleaning' => AgentBrief::INTENT_COMPLAINT,
        'upsell' => AgentBrief::INTENT_BOOKING_CHANGE,
        'review' => AgentBrief::INTENT_OTHER,
        'general' => AgentBrief::INTENT_OTHER,
    ];

    /**
     * Map whatever the provider called the intent onto the fixed set.
     *
     * Unknown becomes `other`, which is never auto-sendable — so a provider
     * inventing a category cannot accidentally open the gate.
     */
    private function normaliseIntent(string $intent): string
    {
        $candidate = str_replace([' ', '-'], '_', mb_strtolower(trim($intent)));

        if (in_array($candidate, AgentBrief::intents(), true)) {
            return $candidate;
        }

        return self::ALIASES[$candidate] ?? AgentBrief::INTENT_OTHER;
    }
}
