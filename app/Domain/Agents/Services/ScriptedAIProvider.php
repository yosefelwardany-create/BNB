<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Integrations\Contracts\AIProviderInterface;
use App\Domain\Integrations\DataObjects\AIClassification;
use App\Domain\Integrations\DataObjects\AICompletion;
use App\Domain\Integrations\DataObjects\AIMessageContext;

/**
 * A provider whose answers are dictated by the test.
 *
 * The agent's job is to decide what a model is allowed to know and whether its
 * answer may be sent. Testing that against a real model would measure the
 * model; testing it against the keyword provider would measure the keywords.
 * Neither tells you whether the gates hold.
 *
 * So the tests say "return this intent with this confidence" and then assert on
 * what the agent did about it — including the cases a real model would rarely
 * produce and which matter most: full confidence on a refund question, an
 * invented intent, a reply that parrots back a door code it was never given.
 *
 * It also records the context it was handed, which is how the important
 * assertion in this feature is written: *the prompt did not contain the secret.*
 */
class ScriptedAIProvider implements AIProviderInterface
{
    /** @var list<AIMessageContext> */
    public array $seen = [];

    public function __construct(
        private string $reply = 'Scripted reply.',
        private string $intent = 'amenity',
        private float $confidence = 0.9,
        private bool $live = true,
    ) {}

    public function reply(string $reply): self
    {
        $this->reply = $reply;

        return $this;
    }

    public function classifyAs(string $intent, float $confidence = 0.9): self
    {
        $this->intent = $intent;
        $this->confidence = $confidence;

        return $this;
    }

    public function notLive(): self
    {
        $this->live = false;

        return $this;
    }

    /**
     * Everything the provider was told, flattened, for asserting on absence.
     */
    public function lastPromptText(): string
    {
        $last = end($this->seen);

        if ($last === false) {
            return '';
        }

        return json_encode([
            $last->messages,
            $last->reservation,
            $last->property,
            $last->organizationVoice,
        ], JSON_UNESCAPED_SLASHES) ?: '';
    }

    public function key(): string
    {
        return 'scripted';
    }

    public function displayName(): string
    {
        return 'Scripted';
    }

    public function isLive(): bool
    {
        return $this->live;
    }

    public function simulationReason(): ?string
    {
        return $this->live ? null : 'Scripted for a test.';
    }

    public function draftReply(AIMessageContext $context, ?string $instruction = null): AICompletion
    {
        $this->seen[] = $context;

        return new AICompletion(
            text: $this->reply,
            provider: $this->key(),
            model: 'scripted-1',
            promptTokens: 100,
            completionTokens: 20,
        );
    }

    public function summarise(AIMessageContext $context): AICompletion
    {
        $this->seen[] = $context;

        return new AICompletion(text: 'Scripted summary.', provider: $this->key());
    }

    public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null): AICompletion
    {
        return new AICompletion(text: $text, provider: $this->key());
    }

    public function classify(AIMessageContext $context): AIClassification
    {
        $this->seen[] = $context;

        return new AIClassification(
            intent: $this->intent,
            urgency: 'normal',
            sentiment: 'neutral',
            confidence: $this->confidence,
            provider: $this->key(),
        );
    }

    public function analyseReview(string $reviewText, ?int $rating = null): AIClassification
    {
        return new AIClassification(
            intent: 'review',
            urgency: 'low',
            sentiment: 'neutral',
            confidence: $this->confidence,
            provider: $this->key(),
        );
    }
}
