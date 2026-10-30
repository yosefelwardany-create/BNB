<?php

declare(strict_types=1);

namespace App\Domain\Websites\Support;

use App\Support\Money\Money;
use Illuminate\Support\HtmlString;
use NumberFormatter;

/**
 * How a guest-facing page writes money, approximate places and lines of text.
 */
final class SiteFormat
{
    public const LOCALE = 'en_GB';

    /**
     * Words typed on several lines, escaped, each line break a <br>. Nothing
     * but the <br>: where the page is being typed in, a newline after it would
     * show as a second break, and be read back as one.
     */
    public static function lines(string $text): HtmlString
    {
        return new HtmlString(str_replace(["\r\n", "\r", "\n"], '<br>', e($text)));
    }

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
