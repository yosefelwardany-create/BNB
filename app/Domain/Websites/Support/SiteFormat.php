<?php

declare(strict_types=1);

namespace App\Domain\Websites\Support;

use App\Support\Money\Money;
use NumberFormatter;

/**
 * How a guest-facing page writes money and approximate places.
 */
final class SiteFormat
{
    public const LOCALE = 'en_GB';

    public static function money(Money $money, bool $wholeUnits = false): string
    {
        $formatter = new NumberFormatter(self::LOCALE, NumberFormatter::CURRENCY);

        if ($wholeUnits) {
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, 0);
        }

        return (string) $formatter->formatCurrency((float) $money->toDecimal(), $money->currency);
    }

    /**
     * A point a kilometre or so from the property, never the door: the exact
     * address is for a guest who has booked and paid, as in the guest portal.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public static function approximately(mixed $latitude, mixed $longitude): ?array
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        return ['latitude' => round((float) $latitude, 2), 'longitude' => round((float) $longitude, 2)];
    }
}
