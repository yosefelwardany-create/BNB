<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\DataObjects\AgentAnswer;
use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Enums\AgentAudience;
use App\Domain\Integrations\Contracts\AIProviderInterface;
use App\Domain\Integrations\DataObjects\AIMessageContext;
use App\Domain\Integrations\Registries\AIProviderRegistry;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;

/**
 * The agent that answers a guest's question about one property.
 *
 * The order of operations is the design. Classify first, then decide what the
 * agent is allowed to know, then draft — because the safe answer to "what's the
 * door code" is not a carefully worded refusal, it is a draft written by a model
 * that was never shown the code.
 *
 * Four gates stand between a guest's question and an answer leaving the
 * building. The first is {@see PropertyKnowledge}, which decides which facts go
 * into the prompt at all — a model cannot leak what it was not given, and no
 * amount of persuasion in the guest's message changes what was assembled before
 * the message was read. The other three live in {@see AgentGates}, which is
 * where they are documented and where the async path reads them from too.
 */
class GuestAgent
{
    public function __construct(
        private readonly AIProviderRegistry $providers,
        private readonly PropertyKnowledge $knowledge,
        private readonly AgentGates $gates,
        private readonly OperatorKnowledge $operator,
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
        AgentAudience $audience = AgentAudience::Guest,
        ?User $asker = null,
    ): AgentAnswer {
        $brief = AgentBrief::fromSettings($property->settings);
        $provider = $this->providerFor($property, $brief);

        // Assembled before the question is looked at, so nothing in the
        // question can influence what the agent is permitted to know. Which
        // body of facts depends on who is asking, and on nothing else: the two
        // are gated by different rules about different people, and a single
        // widened set would put last month's revenue one mistake away from a
        // guest.
        [$facts, $withheld, $stay] = $audience === AgentAudience::Operator
            ? $this->operatorFacts($property, $asker, $question)
            : $this->guestFacts($property, $reservation);

        $context = new AIMessageContext(
            messages: [...$history, ['role' => 'guest', 'body' => $question]],
            reservation: $stay,
            property: $facts,
            guestName: $guestName,
            guestLanguage: $brief->languages[0] ?? 'en',
            organizationVoice: $this->voice($brief, $withheld),
        );

        $classification = $provider->classify($context);
        $intent = $this->gates->normaliseIntent($classification->intent);

        $completion = $provider->draftReply(
            $context,
            $this->instruction($brief, $intent, $withheld, $audience),
        );

        /*
         * The auto-send gates have nothing to decide for an operator.
         *
         * They exist to stop an answer reaching a guest unread. An answer to the
         * person who asked for it has no second party to reach, so running them
         * would produce a "held for review" line about a review that is the act
         * of reading the screen — theatre, and the kind that teaches people to
         * ignore the real ones.
         */
        $held = $audience->isSendable()
            ? $this->gates->reasonToHold($brief, $intent, $question, $classification->confidence, $withheld)
            : null;

        return new AgentAnswer(
            reply: trim($completion->text),
            intent: $intent,
            confidence: $classification->confidence,
            wouldAutoSend: $audience->isSendable() && $held === null,
            heldBecause: $held,
            withheld: $withheld,
            usedFacts: array_keys($facts),
            isSimulated: ! $provider->isLive(),
            simulationReason: $provider->isLive() ? null : $provider->simulationReason(),
            provider: $provider->key(),
            model: $completion->model,
            promptTokens: $completion->promptTokens,
            completionTokens: $completion->completionTokens,
        );
    }

    /**
     * What a guest may be told, and what they may not.
     *
     * @return array{0: array<string, mixed>, 1: list<string>, 2: array<string, mixed>}
     */
    private function guestFacts(Property $property, ?Reservation $reservation): array
    {
        $facts = $this->knowledge->public($property);
        [$arrival, $withheld] = $this->knowledge->arrival($property, $reservation);

        return [
            $facts + ($arrival === [] ? [] : ['arrival' => $arrival]),
            $withheld,
            $this->knowledge->stay($reservation),
        ];
    }

    /**
     * How the property is doing, as far as this person may see it.
     *
     * @return array{0: array<string, mixed>, 1: list<string>, 2: array<string, mixed>}
     */
    private function operatorFacts(Property $property, ?User $asker, string $question): array
    {
        $assembled = $this->operator->about($property, $asker, $question);

        // No stay block: an operator's question is about the property, and a
        // single booking's details would read as the subject of the question
        // rather than one row among the figures.
        return [$assembled['facts'], $assembled['withheld'], []];
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
     * The instruction handed to the provider alongside the context.
     *
     * @param  list<string>  $withheld
     */
    private function instruction(
        AgentBrief $brief,
        string $intent,
        array $withheld,
        AgentAudience $audience = AgentAudience::Guest,
    ): string {
        $lines = $audience === AgentAudience::Operator
            ? [
                // Said first, because everything else follows from it. An agent
                // that thinks it is talking to a guest hedges, apologises and
                // declines to quote a number — which is the opposite of useful
                // to the person who owns the flat.
                'You are answering the property manager about their own property. They are not a guest.',
                PropertyAgentMemory::instruction(),
                'Give them the figures plainly. Quote the numbers you were given, with their currency, and do not round them into vagueness.',
                'Answer only from the facts provided. If a figure is not there, say which one is missing — never estimate one, and never infer a trend from a single window.',
            ]
            : [
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

        if ($withheld !== [] && $audience === AgentAudience::Operator) {
            $lines[] = 'Some figures are deliberately not available to you: '
                .implode(' ', $withheld)
                .' Say which ones you cannot see rather than working around the gap.';
        } elseif ($withheld !== []) {
            $lines[] = 'Arrival details are deliberately not available to you for this guest: '
                .implode(' ', $withheld)
                .' Say that someone will send them, and why, without apologising at length.';
        }

        if ($audience === AgentAudience::Guest) {
            $lines[] = sprintf('The question appears to be about %s.', str_replace('_', ' ', $intent));
        }

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
     * The provider that answers for this property.
     *
     * The brief's choice, falling back to the account's. This is what lets one
     * flat be answered by its own bot while the next is answered by Claude — and
     * the operators this is for have a bot named after each flat, so a single
     * account-wide setting would mean the same bot answering for every property
     * or none of them.
     *
     * A provider whose configuration is the property's gets handed the property.
     * `forProperty()` returns a fresh instance; the registry caches by key, so a
     * provider that configured itself in place would answer the next property's
     * guest from this one's bot.
     *
     * An unknown key falls back rather than throwing. A brief naming a provider
     * that has since been removed is a configuration that went stale, and
     * refusing to answer a guest over it would be the wrong way to report that.
     */
    private function providerFor(Property $property, AgentBrief $brief): AIProviderInterface
    {
        return $this->providers->forProperty($property);
    }
}
