<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * Structured judgement about a message or review.
 *
 * `confidence` matters: automation rules are configured to act only above a
 * threshold, and anything below it is routed to a human instead.
 */
final class AIClassification
{
    /**
     * @param  list<string>  $topics
     * @param  list<string>  $suggestedTasks
     */
    public function __construct(
        public readonly string $intent,
        public readonly string $urgency,        // low|normal|high|critical
        public readonly string $sentiment,      // positive|neutral|negative
        public readonly float $confidence,
        public readonly array $topics = [],
        public readonly array $suggestedTasks = [],
        public readonly ?string $summary = null,
        public readonly string $provider = 'unknown',
    ) {}

    public function isConfident(float $threshold = 0.7): bool
    {
        return $this->confidence >= $threshold;
    }

    public function requiresAttention(): bool
    {
        return in_array($this->urgency, ['high', 'critical'], true)
            || $this->sentiment === 'negative';
    }
}
