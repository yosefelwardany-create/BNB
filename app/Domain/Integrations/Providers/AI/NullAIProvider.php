<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\AI;

use App\Domain\Integrations\Contracts\AIProviderInterface;
use App\Domain\Integrations\DataObjects\AIClassification;
use App\Domain\Integrations\DataObjects\AICompletion;
use App\Domain\Integrations\DataObjects\AIMessageContext;
use App\Domain\Integrations\Exceptions\AIProviderUnavailableException;

/**
 * Declines every request.
 *
 * This is the correct provider for an organization that has not opted into AI
 * assistance. Callers receive an explicit exception rather than silently
 * degraded output, so no feature can quietly pretend a model answered.
 */
class NullAIProvider implements AIProviderInterface
{
    public function key(): string
    {
        return 'null';
    }

    public function displayName(): string
    {
        return 'Disabled';
    }

    public function isLive(): bool
    {
        return false;
    }

    public function draftReply(AIMessageContext $context, ?string $instruction = null): AICompletion
    {
        throw $this->unavailable();
    }

    public function summarise(AIMessageContext $context): AICompletion
    {
        throw $this->unavailable();
    }

    public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null): AICompletion
    {
        throw $this->unavailable();
    }

    public function classify(AIMessageContext $context): AIClassification
    {
        throw $this->unavailable();
    }

    public function analyseReview(string $reviewText, ?int $rating = null): AIClassification
    {
        throw $this->unavailable();
    }

    private function unavailable(): AIProviderUnavailableException
    {
        return new AIProviderUnavailableException(
            'AI assistance is not enabled for this organization.'
        );
    }
}
