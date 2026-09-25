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
        /**
         * Prompt tokens written to, and served from, the provider's cache.
         *
         * Reported rather than inferred, because whether a cache breakpoint took
         * effect is not something the caller can tell from the request. Every
         * model has a minimum cacheable prefix and a prompt below it caches
         * silently — no error, no entry, no saving. A number that comes back
         * zero is the only honest way to find that out.
         */
        public readonly int $cacheWriteTokens = 0,
        public readonly int $cacheReadTokens = 0,
    ) {}

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }
}
