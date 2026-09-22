<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\DataObjects\AIClassification;
use App\Domain\Integrations\DataObjects\AICompletion;
use App\Domain\Integrations\DataObjects\AIMessageContext;

/**
 * Language-model capabilities used by the messaging and review modules.
 *
 * Two rules hold for every implementation:
 *
 *  - Nothing produced here is sent to a guest automatically unless the
 *    organization has explicitly enabled automatic sending for that use.
 *  - Every generated artefact is recorded as AI-generated, so a manager can
 *    always tell what a human wrote and what a model drafted.
 */
interface AIProviderInterface
{
    public function key(): string;

    public function displayName(): string;

    /**
     * Whether the implementation calls an external model. The bundled `echo`
     * provider does not and is labelled accordingly.
     */
    public function isLive(): bool;

    /**
     * Draft a reply to a guest in the organization's voice.
     */
    public function draftReply(AIMessageContext $context, ?string $instruction = null): AICompletion;

    /**
     * Summarise a conversation for a manager picking it up mid-thread.
     */
    public function summarise(AIMessageContext $context): AICompletion;

    /**
     * Translate text into the target language.
     */
    public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null): AICompletion;

    /**
     * Classify an inbound message: intent, urgency, sentiment and whether it
     * implies an operational task.
     */
    public function classify(AIMessageContext $context): AIClassification;

    /**
     * Analyse a review's content into themes and sentiment.
     */
    public function analyseReview(string $reviewText, ?int $rating = null): AIClassification;
}
