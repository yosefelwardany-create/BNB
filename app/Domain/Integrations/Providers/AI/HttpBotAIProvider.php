<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\AI;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Support\BotEndpoint;
use App\Domain\Integrations\Contracts\PerPropertyAIProvider;
use App\Domain\Integrations\DataObjects\AIClassification;
use App\Domain\Integrations\DataObjects\AICompletion;
use App\Domain\Integrations\DataObjects\AIMessageContext;
use App\Domain\Integrations\Exceptions\AIProviderUnavailableException;
use App\Domain\Properties\Models\Property;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The property's own bot, reached over HTTP.
 *
 * For an operator who already runs a bot per flat — one that watches the
 * listing, knows its quirks and has been answering guests for months. Rather
 * than rebuild that knowledge inside Habitat, this hands the bot a question and
 * the facts it is entitled to, and uses what comes back as the draft.
 *
 * ## The contract
 *
 * Habitat POSTs JSON and expects JSON back:
 *
 * ```
 * → { "question": "...", "history": [{"role","body"}], "property": {...},
 *     "facts": {...}, "stay": {...}|null, "guest": {"name": "..."|null} }
 *
 * ← { "reply": "...", "intent": "amenity"|…, "confidence": 0.0–1.0 }
 * ```
 *
 * A bot that answers with plain text works. It just never auto-sends — see
 * below, and that is the design rather than a limitation.
 *
 * ## What this provider is and is not trusted with
 *
 * **It does not decide what it is told.** `PropertyKnowledge` assembles the
 * facts before the guest's question is read, and a door code is in that set only
 * where the booking is confirmed, paid and inside its access window. That gate
 * was built so a language model could not leak what it was never given; it does
 * the same job here, where "somewhere else" is a third party's server rather
 * than a model's context. Worth being plain about all the same: choosing this
 * provider sends the property's facts to a host of the operator's choosing, and
 * the screen that configures it says so.
 *
 * **It does not decide whether its answer is safe to send.** Intent and
 * confidence come back as claims, and Habitat treats them as claims: the intent
 * is intersected with its own allow-list, the confidence is compared against the
 * property's floor, and the escalation keywords are matched in Habitat's code
 * against the guest's words. A bot cannot talk its way past any of those by what
 * it returns, because none of them are questions it is asked.
 *
 * **Silence means hold.** No `confidence` is read as zero, which is below every
 * floor, so a bot that returns only text drafts for review every time. That is
 * the honest default: an answer whose certainty nobody stated has not been
 * established to be certain. A bot earns auto-send by saying how sure it is.
 */
class HttpBotAIProvider implements PerPropertyAIProvider
{
    private ?string $endpoint = null;

    private ?string $token = null;

    private ?string $botName = null;

    private ?string $propertyId = null;

    /**
     * This request's answer, so classify and draft are one call to somebody's bot.
     *
     * @var array<string, array{reply: string, intent: string, confidence: float}>
     */
    private array $answers = [];

    public function key(): string
    {
        return 'bot';
    }

    public function displayName(): string
    {
        return $this->botName ?? 'The property’s own bot';
    }

    public function forProperty(Property $property): static
    {
        $brief = AgentBrief::fromSettings($property->settings);

        // A clone, never `$this`: the registry caches providers by key, so
        // configuring in place would answer the next property's guest from this
        // property's bot.
        $copy = new static;
        $copy->endpoint = $brief->botUrl;
        $copy->botName = $brief->botName;
        $copy->token = $property->agent_bot_token;
        $copy->propertyId = (string) $property->getKey();

        return $copy;
    }

    public function isLive(): bool
    {
        return $this->endpoint !== null && BotEndpoint::permits($this->endpoint);
    }

    public function simulationReason(): ?string
    {
        if ($this->endpoint === null) {
            return $this->propertyId === null
                ? 'No property was selected, so there is no bot to call.'
                : 'This property has no bot endpoint, so there is nothing to ask.';
        }

        return BotEndpoint::permits($this->endpoint)
            ? null
            : 'This property’s bot endpoint is not one Habitat will call. Open the agent settings to see why.';
    }

    public function draftReply(AIMessageContext $context, ?string $instruction = null): AICompletion
    {
        $answer = $this->ask($context, $instruction);

        return new AICompletion(
            text: $answer['reply'],
            provider: $this->key(),
            model: $this->botName ?? $this->host(),
        );
    }

    public function classify(AIMessageContext $context): AIClassification
    {
        $answer = $this->ask($context);

        /*
         * One call answers both.
         *
         * `GuestAgent` classifies and then drafts, which is two calls to a model
         * and would be two calls to somebody's bot — the same question twice,
         * their cost twice, and two chances for the second answer to disagree
         * with the first. The reply is cached for the duration of the request so
         * the bot sees one question and Habitat reads one answer.
         */
        return new AIClassification(
            intent: $answer['intent'],
            urgency: 'normal',
            sentiment: 'neutral',
            // Absent is zero, not a guess. Zero is below every floor, so the
            // draft is held — which is the right answer to "how sure is it?"
            // when nobody said.
            confidence: $answer['confidence'],
            summary: null,
            provider: $this->key(),
        );
    }

    public function summarise(AIMessageContext $context): AICompletion
    {
        throw $this->cannot('summarise a thread');
    }

    public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null): AICompletion
    {
        throw $this->cannot('translate');
    }

    public function analyseReview(string $reviewText, ?int $rating = null): AIClassification
    {
        throw $this->cannot('analyse a review');
    }

    /**
     * The bot's answer to this context, called once per request.
     *
     * @return array{reply: string, intent: string, confidence: float}
     */
    private function ask(AIMessageContext $context, ?string $instruction = null): array
    {
        /*
         * Per instance, emphatically not `static`.
         *
         * A static here looked like the same thing and is not: it lives for the
         * life of the PHP process, and this application runs under a worker that
         * stays up for hours. An answer would be served back minutes later to a
         * different request, the map would grow without bound, and a question
         * repeated to the same endpoint with the same facts would never reach the
         * bot again. An instance property is exactly the scope that was wanted,
         * because `forProperty()` makes a fresh one per request.
         */
        $signature = md5(serialize([$this->endpoint, $context->messages, $context->property, $instruction]));

        if (isset($this->answers[$signature])) {
            return $this->answers[$signature];
        }

        if ($this->endpoint === null) {
            throw new AIProviderUnavailableException((string) $this->simulationReason());
        }

        // Re-checked here and not only when it was saved: a name that was public
        // when somebody typed it can point somewhere else by the time it is used.
        $endpoint = BotEndpoint::parse($this->endpoint);

        $question = $context->lastGuestMessage();

        if ($question === null) {
            throw new AIProviderUnavailableException('There is no guest message to put to the bot.');
        }

        if (! config('pms.outbound.enabled', true)) {
            throw new AIProviderUnavailableException(
                'Calls to property bots are disabled in this environment (OUTBOUND_INTEGRATIONS_ENABLED=false).'
            );
        }

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->withHeaders(array_filter([
                    'Authorization' => $this->token === null ? null : 'Bearer '.$this->token,
                    // So a bot serving several properties knows which is asking
                    // without parsing the body.
                    'X-Habitat-Property' => $this->propertyId,
                ]))
                ->timeout((int) config('pms.agents.bot.timeout', 20))
                // No retry. A bot that is slow or broken should say so to the
                // person waiting, not be asked again while they watch a spinner.
                ->post($endpoint->url, [
                    'question' => $question,
                    'instruction' => $instruction,
                    'history' => array_values(array_filter(
                        $context->messages,
                        static fn (array $turn): bool => ($turn['body'] ?? '') !== $question,
                    )),
                    'property' => ['id' => $this->propertyId] + $this->propertyHeader($context),
                    'facts' => $context->property,
                    'stay' => $context->reservation === [] ? null : $context->reservation,
                    'guest' => ['name' => $context->guestName, 'language' => $context->guestLanguage],
                    'voice' => $context->organizationVoice,
                ]);
        } catch (ConnectionException $e) {
            throw new AIProviderUnavailableException(sprintf(
                'The bot at %s could not be reached: %s',
                $endpoint->host,
                $e->getMessage(),
            ));
        }

        if ($response->failed()) {
            throw new AIProviderUnavailableException(sprintf(
                'The bot at %s answered %d. %s',
                $endpoint->host,
                $response->status(),
                // Its own words, trimmed. A bot's error message is the most
                // useful thing on the screen when somebody is debugging one.
                mb_substr(trim($response->body()), 0, 300) ?: 'It said nothing further.',
            ));
        }

        return $this->answers[$signature] = $this->interpret($response->body());
    }

    /**
     * What the bot said, read defensively.
     *
     * Plain text is accepted because a bot somebody already runs is more likely
     * to return a sentence than a schema, and refusing it would make this
     * unusable for exactly the people it is for. What is never inferred is
     * confidence: text alone carries none, so it comes back zero and the draft
     * waits for a person.
     *
     * @return array{reply: string, intent: string, confidence: float}
     */
    private function interpret(string $body): array
    {
        $decoded = json_decode(trim($body), true);

        if (! is_array($decoded)) {
            return [
                'reply' => trim($body),
                'intent' => AgentBrief::INTENT_OTHER,
                'confidence' => 0.0,
            ];
        }

        $reply = $decoded['reply'] ?? $decoded['text'] ?? $decoded['message'] ?? null;

        if (! is_string($reply) || trim($reply) === '') {
            throw new AIProviderUnavailableException(
                'The bot answered with JSON that carries no reply. It needs a "reply" field, or plain text.'
            );
        }

        $intent = $decoded['intent'] ?? null;
        $confidence = $decoded['confidence'] ?? null;

        return [
            'reply' => trim($reply),
            // An intent Habitat does not know is `other`, which is never
            // auto-sendable — the same answer as not saying.
            'intent' => is_string($intent) && in_array($intent, AgentBrief::intents(), true)
                ? $intent
                : AgentBrief::INTENT_OTHER,
            'confidence' => is_numeric($confidence)
                ? max(0.0, min(1.0, (float) $confidence))
                : 0.0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function propertyHeader(AIMessageContext $context): array
    {
        return array_intersect_key(
            $context->property,
            array_flip(['name', 'timezone', 'city', 'property_type']),
        );
    }

    private function host(): string
    {
        return $this->endpoint === null ? 'bot' : (string) (parse_url($this->endpoint, PHP_URL_HOST) ?: 'bot');
    }

    private function cannot(string $what): AIProviderUnavailableException
    {
        return new AIProviderUnavailableException(sprintf(
            'A property bot answers guest questions; it cannot %s. Set the provider to Claude for that.',
            $what,
        ));
    }
}
