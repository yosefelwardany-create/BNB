<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\AI;

use Anthropic\Client;
use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Integrations\Contracts\AIProviderInterface;
use App\Domain\Integrations\DataObjects\AIClassification;
use App\Domain\Integrations\DataObjects\AICompletion;
use App\Domain\Integrations\DataObjects\AIMessageContext;
use App\Domain\Integrations\Exceptions\AIProviderUnavailableException;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Claude, through the official Anthropic SDK.
 *
 * The first genuinely live provider in this product that needs no partner
 * agreement — an API key is self-serve — which is why it exists before any of
 * the OTA adapters.
 *
 * Four decisions worth stating:
 *
 * **Classification uses structured output, not parsing.** `outputConfig` with a
 * JSON schema means the intent comes back as one of the categories the agent
 * actually gates on, and `confidence` comes back as a number. Asking for JSON in
 * a prompt and then parsing it is the same thing with a failure mode: the day it
 * returns prose, the intent silently becomes `other` and a real question gets
 * held for no stated reason.
 *
 * **The facts go in the system prompt, cached.** They are stable across a
 * conversation and large relative to the question, so a cache breakpoint after
 * them means a ten-message thread pays for them once. The volatile part — the
 * question — comes after, because caching is a prefix match and anything
 * changing early invalidates everything later.
 *
 * **No key means not live, and it says so.** Same contract as every other
 * provider here: `isLive()` is derived from configuration rather than asserted,
 * so the same code is honest in development and in production without anybody
 * remembering to change a flag.
 *
 * **A failure is an exception, not an empty draft.** A blank reply that looks
 * like a considered answer is the worst available outcome; the caller holds the
 * message and a person writes it.
 */
class ClaudeAIProvider implements AIProviderInterface
{
    /**
     * Wide enough for a guest reply with room to spare. Hitting the cap
     * truncates mid-sentence, which reads as a bug to whoever reviews it.
     */
    private const MAX_TOKENS = 2048;

    private ?Client $client = null;

    public function __construct(private readonly Container $container) {}

    public function key(): string
    {
        return 'claude';
    }

    public function displayName(): string
    {
        return 'Claude';
    }

    public function isLive(): bool
    {
        return $this->apiKey() !== null;
    }

    public function simulationReason(): ?string
    {
        if ($this->isLive()) {
            return null;
        }

        return 'No Anthropic API key is configured, so nothing is sent to a model. '
            .'Set ANTHROPIC_API_KEY to make this live.';
    }

    public function draftReply(AIMessageContext $context, ?string $instruction = null): AICompletion
    {
        $message = $this->call(
            system: [
                // Stable prefix first, cached: the operating instructions and
                // the property's facts do not change between turns of one
                // conversation.
                ['type' => 'text', 'text' => $this->replySystemPrompt($instruction, $context)],
                ['type' => 'text', 'text' => $this->factsBlock($context), 'cacheControl' => ['type' => 'ephemeral']],
            ],
            messages: $this->transcript($context),
        );

        return new AICompletion(
            text: $this->textOf($message),
            provider: $this->key(),
            model: $this->model(),
            promptTokens: (int) ($message->usage->inputTokens ?? 0),
            completionTokens: (int) ($message->usage->outputTokens ?? 0),
            finishReason: $message->stopReason ?? null,
        );
    }

    public function summarise(AIMessageContext $context): AICompletion
    {
        $message = $this->call(
            system: 'Summarise this guest conversation for a colleague picking it up cold. '
                .'Lead with what the guest is waiting for. No more than four sentences.',
            messages: $this->transcript($context),
        );

        return new AICompletion(
            text: $this->textOf($message),
            provider: $this->key(),
            model: $this->model(),
            promptTokens: (int) ($message->usage->inputTokens ?? 0),
            completionTokens: (int) ($message->usage->outputTokens ?? 0),
        );
    }

    public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null): AICompletion
    {
        $message = $this->call(
            system: sprintf(
                'Translate into %s. Return only the translation, with the register and line breaks of the original. '
                .'A guest message is not an occasion for improved prose.',
                $targetLanguage,
            ),
            messages: [['role' => 'user', 'content' => $text]],
        );

        return new AICompletion(
            text: $this->textOf($message),
            provider: $this->key(),
            model: $this->model(),
            promptTokens: (int) ($message->usage->inputTokens ?? 0),
            completionTokens: (int) ($message->usage->outputTokens ?? 0),
        );
    }

    public function classify(AIMessageContext $context): AIClassification
    {
        $message = $this->call(
            system: 'Classify the guest\'s latest message. Judge only what they are asking for, '
                .'not how politely they ask. `confidence` is how sure you are of the category: '
                .'be honest rather than generous, because a low number holds the reply for a '
                .'person to read, which is the outcome you want when you are unsure.',
            messages: $this->transcript($context),
            outputConfig: ['format' => [
                'type' => 'json_schema',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'intent' => ['type' => 'string', 'enum' => AgentBrief::intents()],
                        'urgency' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'critical']],
                        'sentiment' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative']],
                        'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        'topics' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'summary' => ['type' => 'string'],
                    ],
                    'required' => ['intent', 'urgency', 'sentiment', 'confidence', 'topics', 'summary'],
                    'additionalProperties' => false,
                ],
            ]],
        );

        $parsed = $this->parsedOf($message);

        return new AIClassification(
            intent: (string) ($parsed['intent'] ?? AgentBrief::INTENT_OTHER),
            urgency: (string) ($parsed['urgency'] ?? 'normal'),
            sentiment: (string) ($parsed['sentiment'] ?? 'neutral'),
            confidence: (float) ($parsed['confidence'] ?? 0.0),
            topics: array_values(array_map('strval', (array) ($parsed['topics'] ?? []))),
            summary: isset($parsed['summary']) ? (string) $parsed['summary'] : null,
            provider: $this->key(),
        );
    }

    public function analyseReview(string $reviewText, ?int $rating = null): AIClassification
    {
        $message = $this->call(
            system: 'Analyse this review. `intent` is always "review".',
            messages: [['role' => 'user', 'content' => $rating === null
                ? $reviewText
                : sprintf('Rated %d. %s', $rating, $reviewText)]],
            outputConfig: ['format' => [
                'type' => 'json_schema',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'sentiment' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative']],
                        'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        'topics' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'suggested_tasks' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'summary' => ['type' => 'string'],
                    ],
                    'required' => ['sentiment', 'confidence', 'topics', 'suggested_tasks', 'summary'],
                    'additionalProperties' => false,
                ],
            ]],
        );

        $parsed = $this->parsedOf($message);

        // An explicit star rating is a stronger signal than the wording, so it
        // wins — the same rule the local provider applies.
        $sentiment = match (true) {
            $rating !== null && $rating <= 2 => 'negative',
            $rating !== null && $rating >= 4 => 'positive',
            $rating !== null => 'neutral',
            default => (string) ($parsed['sentiment'] ?? 'neutral'),
        };

        return new AIClassification(
            intent: 'review',
            urgency: $sentiment === 'negative' ? 'high' : 'low',
            sentiment: $sentiment,
            confidence: (float) ($parsed['confidence'] ?? 0.0),
            topics: array_values(array_map('strval', (array) ($parsed['topics'] ?? []))),
            suggestedTasks: array_values(array_map('strval', (array) ($parsed['suggested_tasks'] ?? []))),
            summary: isset($parsed['summary']) ? (string) $parsed['summary'] : null,
            provider: $this->key(),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>|string  $system
     * @param  list<array<string, mixed>>  $messages
     * @param  array<string, mixed>|null  $outputConfig
     */
    private function call(array|string $system, array $messages, ?array $outputConfig = null): object
    {
        $key = $this->apiKey();

        if ($key === null) {
            throw new AIProviderUnavailableException(
                'No Anthropic API key is configured. Set ANTHROPIC_API_KEY, or configure a different AI provider.',
            );
        }

        // Built as an array and unpacked, because PHP forbids unpacking after a
        // named argument and `outputConfig` is only present for the calls that
        // want a schema-constrained answer.
        $arguments = [
            'maxTokens' => self::MAX_TOKENS,
            'messages' => $messages,
            'model' => $this->model(),
            'system' => $system,
        ];

        if ($outputConfig !== null) {
            $arguments['outputConfig'] = $outputConfig;
        }

        try {
            return $this->client($key)->messages->create(...$arguments);
        } catch (Throwable $exception) {
            // Wrapped rather than allowed to surface: every caller of this
            // interface already knows how to hold a message when the provider
            // is unavailable, and none of them should learn the SDK's
            // exception hierarchy.
            throw new AIProviderUnavailableException(
                sprintf('Claude could not be reached: %s', $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    private function client(string $key): Client
    {
        // A bound client is used in preference to a fresh one, which is how the
        // tests point the SDK at a transporter that records the request instead
        // of sending it. That is worth a seam: the things most likely to be
        // wrong here are the shape of the request — whether the facts really
        // carry a cache breakpoint, whether the schema really constrains the
        // intent — and none of them can be checked from the outside of a live
        // call. Nothing in production binds it.
        if ($this->container->bound(Client::class)) {
            return $this->container->make(Client::class);
        }

        return $this->client ??= new Client(apiKey: $key);
    }

    private function apiKey(): ?string
    {
        $key = config('services.anthropic.key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    private function model(): string
    {
        return (string) config('services.anthropic.model', 'claude-opus-5');
    }

    /**
     * The visible text of a response.
     *
     * Blocks are a polymorphic union and the first one is not necessarily text —
     * with thinking on, it is not — so every block is checked rather than
     * indexed into.
     */
    private function textOf(object $message): string
    {
        $text = '';

        foreach ($message->content ?? [] as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text;
            }
        }

        return trim($text);
    }

    /**
     * The structured output of a response, as an array.
     *
     * @return array<string, mixed>
     */
    private function parsedOf(object $message): array
    {
        if (method_exists($message, 'parsedOutput')) {
            $parsed = $message->parsedOutput();

            if (is_array($parsed)) {
                return $parsed;
            }

            if (is_object($parsed)) {
                return (array) $parsed;
            }
        }

        // The schema constrains the response, so the text is JSON. If it is
        // not, an empty array degrades to intent `other` and zero confidence —
        // which holds the message, the outcome we want on an unreadable answer.
        $decoded = json_decode($this->textOf($message), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * The conversation as the API wants it.
     *
     * @return list<array<string, mixed>>
     */
    private function transcript(AIMessageContext $context): array
    {
        $messages = [];

        foreach ($context->messages as $entry) {
            $role = ($entry['role'] ?? 'guest') === 'guest' ? 'user' : 'assistant';
            $body = trim((string) ($entry['body'] ?? ''));

            if ($body === '') {
                continue;
            }

            // Consecutive same-role entries are legal and combined by the API,
            // so an internal note removed from the middle of a thread does not
            // need patching over here.
            $messages[] = ['role' => $role, 'content' => $body];
        }

        // The API requires the first message to be from the user.
        while ($messages !== [] && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }

        return $messages === []
            ? [['role' => 'user', 'content' => '(no message)']]
            : $messages;
    }

    private function replySystemPrompt(?string $instruction, AIMessageContext $context): string
    {
        $lines = array_filter([
            $instruction ?? 'You are replying to a guest on behalf of the host.',
            $context->guestName === null ? null : sprintf('The guest is %s.', $context->guestName),
            $context->guestLanguage === null ? null : sprintf('Reply in %s.', $context->guestLanguage),
            'Write only the message body. No subject line, no signature, no placeholders in brackets.',
        ]);

        return implode("\n", $lines);
    }

    private function factsBlock(AIMessageContext $context): string
    {
        return "These are the only facts you have. Anything not here, you do not know.\n\n"
            .'PROPERTY: '.json_encode($context->property, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n\n"
            .'THIS BOOKING: '.json_encode($context->reservation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
