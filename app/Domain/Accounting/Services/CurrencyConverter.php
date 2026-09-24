<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\ExchangeRate;
use App\Domain\Integrations\Registries\ExchangeRateProviderRegistry;
use App\Domain\Platform\Support\PlanFeature;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Converting between currencies, and recording what rate was used.
 *
 * The important design point is what happens when a rate is unknown, because
 * that decides whether this feature is trustworthy or merely present:
 *
 *  - `rateFor()` returns null. It never guesses.
 *  - `convert()` refuses with a 422 naming the pair and the date, so a caller
 *    gets an answerable message rather than a plausible wrong number.
 *  - `rateOrParity()` exists for the one legitimate case — a single-currency
 *    organization, where the base and the transaction currency are the same and
 *    no rate is involved at all.
 *
 * What is *not* here is a fallback to 1.0 for a genuinely unknown pair. That was
 * the previous behaviour by omission, and it is the worst available answer:
 * plausible, silently wrong, and wrong by exactly the amount nobody notices
 * until an audit.
 */
class CurrencyConverter
{
    public function __construct(
        private readonly ExchangeRateProviderRegistry $providers,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * The rate to multiply an amount in `$from` by to express it in `$to`.
     */
    public function rateFor(string $from, string $to, ?CarbonImmutable $on = null): ?float
    {
        if (strtoupper($from) === strtoupper($to)) {
            return 1.0;
        }

        return $this->providers->default()->rate($from, $to, $on);
    }

    /**
     * The rate, or 1.0 when no conversion is involved.
     *
     * For the common case: an organization trading only in its own currency. It
     * must not be made to depend on a rate table it has no reason to populate.
     */
    public function rateOrParity(string $from, string $to, ?CarbonImmutable $on = null): float
    {
        if (strtoupper($from) === strtoupper($to)) {
            return 1.0;
        }

        $rate = $this->rateFor($from, $to, $on);

        if ($rate === null) {
            $this->refuse($from, $to, $on);
        }

        return $rate;
    }

    /**
     * Convert an amount, refusing rather than guessing.
     */
    public function convert(Money $amount, string $to, ?CarbonImmutable $on = null): Money
    {
        if (strtoupper($amount->currency) === strtoupper($to)) {
            return $amount;
        }

        $rate = $this->rateFor($amount->currency, $to, $on);

        if ($rate === null) {
            $this->refuse($amount->currency, $to, $on);
        }

        // Rounded once, at the end, to the target currency's minor unit. A chain
        // of conversions each rounding to the cent accumulates error that shows
        // up as a ledger that will not balance.
        return Money::of((int) round($amount->minorUnits * $rate), strtoupper($to));
    }

    /**
     * Whether this organization is permitted to trade in more than one currency.
     *
     * The plan gate, checked here rather than in each caller so a booking in a
     * foreign currency cannot slip in through a path that forgot.
     */
    public function assertMultiCurrencyAllowed(string $transactionCurrency): void
    {
        $organization = $this->tenancy->organizationOrFail();

        if (strtoupper($transactionCurrency) === strtoupper($organization->base_currency)) {
            return;
        }

        if (! $organization->allows(PlanFeature::MULTI_CURRENCY)) {
            throw new HttpException(402, sprintf(
                'Trading in %s requires multi-currency, which your plan does not include. '
                .'This organization reports in %s.',
                strtoupper($transactionCurrency),
                $organization->base_currency,
            ));
        }
    }

    /**
     * Record a rate for a day.
     *
     * Upserted on the pair and the date, so a corrected import replaces rather
     * than adding a second row — two rates for the same day would make every
     * conversion a coin toss between them.
     */
    public function record(
        string $from,
        string $to,
        float|string $rate,
        ?CarbonImmutable $on = null,
        string $source = 'manual',
    ): ExchangeRate {
        if ((float) $rate <= 0.0) {
            throw new HttpException(422, 'An exchange rate must be greater than zero.');
        }

        if (strtoupper($from) === strtoupper($to)) {
            throw new HttpException(422, 'A currency does not have a rate against itself.');
        }

        return ExchangeRate::query()->updateOrCreate(
            [
                'base_currency' => strtoupper($from),
                'quote_currency' => strtoupper($to),
                'rate_date' => ($on ?? CarbonImmutable::today())->toDateString(),
            ],
            ['rate' => $rate, 'source' => $source],
        );
    }

    /**
     * @return never
     */
    private function refuse(string $from, string $to, ?CarbonImmutable $on): void
    {
        throw new HttpException(422, sprintf(
            'No exchange rate is known for %s to %s on %s. Record one before trading in it — '
            .'a conversion at a guessed rate misstates every figure derived from it.',
            strtoupper($from),
            strtoupper($to),
            ($on ?? CarbonImmutable::today())->toDateString(),
        ));
    }
}
