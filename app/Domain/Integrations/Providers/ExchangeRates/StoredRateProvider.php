<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Providers\ExchangeRates;

use App\Domain\Accounting\Models\ExchangeRate;
use App\Domain\Integrations\Contracts\ExchangeRateProviderInterface;
use Carbon\CarbonImmutable;

/**
 * Rates read from this platform's own table.
 *
 * A working implementation rather than a stub: it answers from real stored data,
 * handles the inverse pair, and refuses what it does not know. What it does not
 * do is *fetch* anything — the rates get there by import or by hand.
 *
 * So `isLive()` is false, and the reason says what is missing. This is the same
 * shape as every other integration here: the whole conversion path is exercised
 * for real, and the platform is honest that nobody is subscribing to a rate feed
 * on the customer's behalf.
 *
 * Three behaviours worth naming:
 *
 *  - **An identical pair is 1.0** without consulting anything. EUR to EUR is not
 *    a rate lookup and must not be able to fail.
 *  - **The inverse is derived** when only one direction is stored, because
 *    storing both halves of every pair doubles the import and invites the two
 *    from drifting.
 *  - **The most recent rate on or before the date** is used, because markets
 *    close. A Sunday converts at Friday's rate.
 */
class StoredRateProvider implements ExchangeRateProviderInterface
{
    public function key(): string
    {
        return 'stored';
    }

    public function displayName(): string
    {
        return 'Stored rates';
    }

    public function isLive(): bool
    {
        return false;
    }

    public function simulationReason(): ?string
    {
        return 'Rates are read from this platform\'s own table and are not fetched from a '
            .'market feed. Import them, or set EXCHANGE_RATE_PROVIDER to a provider that '
            .'subscribes to one.';
    }

    public function rate(string $from, string $to, ?CarbonImmutable $on = null): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        // Not a lookup, and must not be able to fail.
        if ($from === $to) {
            return 1.0;
        }

        $on ??= CarbonImmutable::today();

        $direct = ExchangeRate::query()
            ->forPair($from, $to)
            ->asOf($on)
            ->value('rate');

        if ($direct !== null) {
            return (float) $direct;
        }

        // Derived rather than requiring both halves of every pair to be stored:
        // two rows that are supposed to be reciprocals eventually are not.
        $inverse = ExchangeRate::query()
            ->forPair($to, $from)
            ->asOf($on)
            ->value('rate');

        if ($inverse !== null && (float) $inverse > 0.0) {
            return 1.0 / (float) $inverse;
        }

        // Unknown. Returning 1.0 here would be the worst available answer:
        // plausible, silently wrong, and wrong by exactly the amount nobody
        // notices until an audit.
        return null;
    }

    public function supportedCurrencies(): array
    {
        return ExchangeRate::query()
            ->selectRaw('base_currency as code')
            ->union(ExchangeRate::query()->selectRaw('quote_currency as code'))
            ->pluck('code')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
