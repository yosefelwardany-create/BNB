<?php

declare(strict_types=1);

namespace App\Support\Money;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An immutable money value object.
 *
 * Every monetary amount in the platform is stored as an integer number of
 * minor units (cents, fils, pence, ...) together with an ISO-4217 currency
 * code. Floating point numbers are never used for money: they cannot
 * represent decimal fractions exactly and would silently corrupt ledgers.
 */
final class Money implements JsonSerializable, Stringable
{
    /**
     * Currencies whose minor unit is not 1/100 of the major unit.
     *
     * @var array<string, int>
     */
    private const EXPONENTS = [
        'BHD' => 3, 'BIF' => 0, 'CLF' => 4, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0,
        'IQD' => 3, 'ISK' => 0, 'JOD' => 3, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
        'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'PYG' => 0, 'RWF' => 0, 'TND' => 3,
        'UGX' => 0, 'UYW' => 4, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0,
        'XPF' => 0,
    ];

    private function __construct(
        public readonly int $minorUnits,
        public readonly string $currency,
    ) {}

    /**
     * Build a Money instance from an integer amount of minor units.
     */
    public static function of(int $minorUnits, string $currency): self
    {
        return new self($minorUnits, self::normaliseCurrency($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, self::normaliseCurrency($currency));
    }

    /**
     * Build a Money instance from a decimal string such as "129.50".
     *
     * A string (not a float) is required so that the caller never loses
     * precision before the value reaches this class.
     */
    public static function fromDecimal(string|int $amount, string $currency): self
    {
        $currency = self::normaliseCurrency($currency);
        $amount = trim((string) $amount);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException("Unable to parse [{$amount}] as a decimal amount.");
        }

        $exponent = self::exponent($currency);
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '-');

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $fraction = substr(str_pad($fraction, $exponent, '0'), 0, $exponent + 1);

        // Round half-up on the first discarded digit.
        $roundUp = strlen($fraction) > $exponent && (int) $fraction[$exponent] >= 5;
        $fraction = substr($fraction, 0, $exponent);

        $minor = (int) ($whole.$fraction) + ($roundUp ? 1 : 0);

        return new self($negative ? -$minor : $minor, $currency);
    }

    /**
     * The number of decimal places used by the given currency.
     */
    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[self::normaliseCurrency($currency)] ?? 2;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    /**
     * Multiply by a scalar, rounding half-up to the nearest minor unit.
     */
    public function multiply(float|int|string $factor): self
    {
        $product = (float) $this->minorUnits * (float) $factor;

        return new self(self::roundHalfUp($product), $this->currency);
    }

    /**
     * Apply a percentage (e.g. 7.5 for 7.5%).
     */
    public function percentage(float|int|string $percent): self
    {
        return $this->multiply((float) $percent / 100);
    }

    public function negate(): self
    {
        return new self(-$this->minorUnits, $this->currency);
    }

    public function absolute(): self
    {
        return new self(abs($this->minorUnits), $this->currency);
    }

    /**
     * Split the amount into $n parts, distributing the remainder one minor
     * unit at a time so that the parts always sum back to the original.
     *
     * @return array<int, self>
     */
    public function allocateEvenly(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Cannot allocate money across fewer than one part.');
        }

        $base = intdiv($this->minorUnits, $parts);
        $remainder = $this->minorUnits - ($base * $parts);
        $sign = $remainder < 0 ? -1 : 1;
        $remainder = abs($remainder);

        $result = [];
        for ($i = 0; $i < $parts; $i++) {
            $extra = $i < $remainder ? $sign : 0;
            $result[] = new self($base + $extra, $this->currency);
        }

        return $result;
    }

    /**
     * Allocate the amount proportionally to the given weights. The parts
     * always sum exactly back to the original amount.
     *
     * @param  array<int|string, float|int>  $weights
     * @return array<int|string, self>
     */
    public function allocateByWeights(array $weights): array
    {
        $total = array_sum($weights);

        if ($total <= 0) {
            throw new InvalidArgumentException('Allocation weights must sum to a positive number.');
        }

        $allocated = [];
        $distributed = 0;

        foreach ($weights as $key => $weight) {
            $share = self::roundHalfUp($this->minorUnits * ((float) $weight / $total));
            $allocated[$key] = $share;
            $distributed += $share;
        }

        // Push any rounding drift onto the largest share so the sum is exact.
        $drift = $this->minorUnits - $distributed;
        if ($drift !== 0 && $allocated !== []) {
            $largest = array_keys($allocated, max($allocated), true)[0];
            $allocated[$largest] += $drift;
        }

        return array_map(fn (int $minor): self => new self($minor, $this->currency), $allocated);
    }

    /**
     * Sum a list of Money instances. The currency is required so that an
     * empty list still produces a well-defined zero.
     *
     * @param  iterable<self>  $items
     */
    public static function sum(iterable $items, string $currency): self
    {
        $total = self::zero($currency);

        foreach ($items as $item) {
            $total = $total->add($item);
        }

        return $total;
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minorUnits === $other->minorUnits;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits > $other->minorUnits;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits >= $other->minorUnits;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits < $other->minorUnits;
    }

    public function lessThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->minorUnits <= $other->minorUnits;
    }

    /**
     * Render the amount as a plain decimal string, e.g. "1299.50".
     */
    public function toDecimal(): string
    {
        $exponent = self::exponent($this->currency);

        if ($exponent === 0) {
            return (string) $this->minorUnits;
        }

        $negative = $this->minorUnits < 0;
        $digits = str_pad((string) abs($this->minorUnits), $exponent + 1, '0', STR_PAD_LEFT);

        $whole = substr($digits, 0, -$exponent);
        $fraction = substr($digits, -$exponent);

        return ($negative ? '-' : '').$whole.'.'.$fraction;
    }

    /**
     * @return array{amount: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->minorUnits,
            'currency' => $this->currency,
            'formatted' => $this->toDecimal(),
        ];
    }

    public function __toString(): string
    {
        return $this->toDecimal().' '.$this->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new CurrencyMismatchException(
                "Cannot operate on {$this->currency} and {$other->currency} without an explicit conversion."
            );
        }
    }

    private static function normaliseCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("[{$currency}] is not a valid ISO-4217 currency code.");
        }

        return $currency;
    }

    /**
     * Round half away from zero, which is the convention used for consumer
     * pricing and matches what finance teams expect on an invoice.
     */
    private static function roundHalfUp(float $value): int
    {
        return (int) ($value < 0 ? -floor(-$value + 0.5) : floor($value + 0.5));
    }
}
