<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * Text produced by a model, with the accounting needed to attribute cost and
 * to mark the output as machine-generated wherever it is shown.
 */
final class AICompletion
{
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly ?string $model = null,
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
        public readonly ?string $finishReason = null,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
