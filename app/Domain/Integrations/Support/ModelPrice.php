<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\DataObjects\AICompletion;
use App\Support\Money;

/**
 * What one model's tokens cost, and what a given call therefore cost.
 *
 * A separate object rather than arithmetic inside a command, for one reason: the
 * absent case has to be representable. Prices are a local copy of a published
 * list and lists go stale, so a model nobody has recorded a price for must come
 * back as "unknown", not as zero and not as a guess. A cost report that is
 * confidently wrong is worse than one that admits a gap — somebody budgets
 * against the first and investigates the second.
 *
 * Money is in dollars as a float here, deliberately unlike every other amount in
 * this platform, which is integer minor units through {@see Money}.
 * That rule exists because a cent lost in a ledger is a defect. This is an
 * estimate of a third party's bill, quoted to a person reading a terminal, and
 * fractions of a cent are the normal unit — forcing it into the money type would
 * imply an authority it does not have.
 */
final class ModelPrice
{
    private function __construct(
        public readonly string $model,
        public readonly float $inputPerMillion,
        public readonly float $outputPerMillion,
        public readonly float $cacheWritePerMillion,
        public readonly float $cacheReadPerMillion,
    ) {}

    /**
     * The recorded price for a model, or null when none is on file.
     */
    public static function forModel(?string $model): ?self
    {
        if ($model === null) {
            return null;
        }

        $prices = config('services.anthropic.prices');
        $row = is_array($prices) ? ($prices[$model] ?? null) : null;

        if (! is_array($row) || ! isset($row['input'], $row['output'])) {
            return null;
        }

        return new self(
            model: $model,
            inputPerMillion: (float) $row['input'],
            outputPerMillion: (float) $row['output'],
            // A provider that does not price caching separately is charged at
            // the input rate for both, which is the conservative reading.
            cacheWritePerMillion: (float) ($row['cache_write'] ?? $row['input']),
            cacheReadPerMillion: (float) ($row['cache_read'] ?? $row['input']),
        );
    }

    /**
     * The smallest prompt this model will cache, or null when it is not known.
     *
     * Below it a cache breakpoint is a silent no-op.
     */
    public function cacheMinimumTokens(): ?int
    {
        $minimums = config('services.anthropic.cache_minimums');
        $minimum = is_array($minimums) ? ($minimums[$this->model] ?? null) : null;

        return is_int($minimum) ? $minimum : null;
    }

    /**
     * What this completion cost, in dollars.
     *
     * Cached prompt tokens are billed at their own rates and are *not* also
     * counted as input: the provider reports them separately, and adding them
     * twice would overstate a cached call by roughly the saving caching made.
     */
    public function costOf(AICompletion $completion): float
    {
        return $this->perMillion($completion->promptTokens, $this->inputPerMillion)
            + $this->perMillion($completion->completionTokens, $this->outputPerMillion)
            + $this->perMillion($completion->cacheWriteTokens, $this->cacheWritePerMillion)
            + $this->perMillion($completion->cacheReadTokens, $this->cacheReadPerMillion);
    }

    /**
     * A cost formatted for a person, down to a hundredth of a cent.
     *
     * Two decimal places would print "$0.00" for most single calls, which reads
     * as free rather than as cheap.
     */
    public function format(float $dollars): string
    {
        return $dollars < 0.01
            ? sprintf('$%.5f', $dollars)
            : sprintf('$%.2f', $dollars);
    }

    private function perMillion(int $tokens, float $rate): float
    {
        return $tokens / 1_000_000 * $rate;
    }
}
