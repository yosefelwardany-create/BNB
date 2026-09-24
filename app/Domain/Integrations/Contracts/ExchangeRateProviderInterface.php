<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use Carbon\CarbonImmutable;

/**
 * Where a foreign exchange rate comes from.
 *
 * Every monetary table in this schema already carries `base_amount`,
 * `base_currency` and the `exchange_rate` used, and the arithmetic against them
 * is correct — but nothing supplied a rate, so every conversion ran at parity.
 * That is a gap with a particular shape: it is invisible while a customer trades
 * in one currency and silently wrong the day they do not.
 *
 * Two properties this contract insists on, both because of what the rates are
 * for rather than where they come from:
 *
 *  - **A rate is asked for by date, not "now".** A booking taken in March and
 *    reported on in September must convert at March's rate. A provider that only
 *    answers with today's figure makes last year's accounts change every time
 *    somebody opens them.
 *
 *  - **A rate that is not known is refused, never guessed.** Returning 1.0 for
 *    an unknown pair is the worst possible answer: it is plausible, it is
 *    silently wrong, and it is wrong by exactly the amount nobody notices until
 *    an audit.
 */
interface ExchangeRateProviderInterface
{
    public function key(): string;

    public function displayName(): string;

    /**
     * Whether this implementation reaches a real rate source.
     */
    public function isLive(): bool;

    /**
     * Why it does not, phrased for a person. Null when it does.
     */
    public function simulationReason(): ?string;

    /**
     * The rate to multiply an amount in `$from` by to express it in `$to`.
     *
     * Returns null when the rate for that pair on that date is not known. The
     * caller decides what to do about it; this must never invent one.
     */
    public function rate(string $from, string $to, ?CarbonImmutable $on = null): ?float;

    /**
     * The currencies this provider can convert between.
     *
     * @return list<string>
     */
    public function supportedCurrencies(): array;
}
