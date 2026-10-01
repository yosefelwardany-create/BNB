<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\DataObjects\AgentAnswer;
use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Integrations\Contracts\AIProviderInterface;
use App\Domain\Integrations\Contracts\PerPropertyAIProvider;
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
        $provider = $this->providerFor($property, $brief);

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
        $intent = $this->gates->normaliseIntent($classification->intent);

        $completion = $provider->draftReply($context, $this->instruction($brief, $intent, $withheld));

        $held = $this->gates->reasonToHold($brief, $intent, $question, $classification->confidence, $withheld);

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
        $provider = $brief->provider !== null && $this->providers->has($brief->provider)
            ? $this->providers->make($brief->provider)
            : $this->providers->default();

        return $provider instanceof PerPropertyAIProvider
            ? $provider->forProperty($property)
            : $provider;
    }
}
