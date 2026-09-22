<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money\CurrencyMismatchException;
use App\Support\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * Money is the foundation every financial figure in the product rests on, so
 * its edge cases are covered directly rather than only through the features
 * that use it.
 */
class MoneyTest extends TestCase
{
    public function test_it_parses_decimal_strings_without_losing_precision(): void
    {
        $this->assertSame(12950, Money::fromDecimal('129.50', 'USD')->minorUnits);
        $this->assertSame(12900, Money::fromDecimal('129', 'USD')->minorUnits);
        $this->assertSame(1, Money::fromDecimal('0.01', 'USD')->minorUnits);
        $this->assertSame(-12950, Money::fromDecimal('-129.50', 'USD')->minorUnits);
    }

    public function test_it_rounds_half_up_when_parsing_more_decimals_than_the_currency_has(): void
    {
        $this->assertSame(1235, Money::fromDecimal('12.345', 'USD')->minorUnits);
        $this->assertSame(1234, Money::fromDecimal('12.344', 'USD')->minorUnits);
    }

    public function test_it_honours_currencies_with_unusual_minor_units(): void
    {
        // The yen has no minor unit.
        $this->assertSame(1500, Money::fromDecimal('1500', 'JPY')->minorUnits);
        $this->assertSame('1500', Money::of(1500, 'JPY')->toDecimal());

        // The dinar has three.
        $this->assertSame(1500, Money::fromDecimal('1.500', 'KWD')->minorUnits);
        $this->assertSame('1.500', Money::of(1500, 'KWD')->toDecimal());
    }

    public function test_it_renders_decimals_including_negatives_and_sub_unit_amounts(): void
    {
        $this->assertSame('129.50', Money::of(12950, 'USD')->toDecimal());
        $this->assertSame('0.05', Money::of(5, 'USD')->toDecimal());
        $this->assertSame('-3.20', Money::of(-320, 'USD')->toDecimal());
        $this->assertSame('0.00', Money::zero('USD')->toDecimal());
    }

    public function test_it_refuses_to_combine_different_currencies(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of(100, 'USD')->add(Money::of(100, 'EUR'));
    }

    public function test_percentages_round_half_up(): void
    {
        // 7.5% of 10.05 is 0.75375 -> 0.75
        $this->assertSame(75, Money::of(1005, 'USD')->percentage(7.5)->minorUnits);

        // 10% of 1.05 is 0.105 -> 0.11
        $this->assertSame(11, Money::of(105, 'USD')->percentage(10)->minorUnits);
    }

    public function test_even_allocation_never_loses_or_creates_a_minor_unit(): void
    {
        $parts = Money::of(1000, 'USD')->allocateEvenly(3);

        $this->assertCount(3, $parts);
        $this->assertSame([334, 333, 333], array_map(fn (Money $m): int => $m->minorUnits, $parts));
        $this->assertSame(1000, array_sum(array_map(fn (Money $m): int => $m->minorUnits, $parts)));
    }

    public function test_even_allocation_handles_negative_amounts(): void
    {
        $parts = Money::of(-1000, 'USD')->allocateEvenly(3);

        $this->assertSame(-1000, array_sum(array_map(fn (Money $m): int => $m->minorUnits, $parts)));
    }

    public function test_weighted_allocation_sums_exactly_to_the_original(): void
    {
        // A three-way owner split that does not divide evenly.
        $shares = Money::of(100_00, 'USD')->allocateByWeights(['a' => 33.33, 'b' => 33.33, 'c' => 33.34]);

        $total = array_sum(array_map(fn (Money $m): int => $m->minorUnits, $shares));

        $this->assertSame(10000, $total);
        $this->assertArrayHasKey('a', $shares);
        $this->assertArrayHasKey('c', $shares);
    }

    public function test_weighted_allocation_rejects_non_positive_weights(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of(100, 'USD')->allocateByWeights(['a' => 0, 'b' => 0]);
    }

    public function test_comparisons(): void
    {
        $ten = Money::of(1000, 'USD');
        $twenty = Money::of(2000, 'USD');

        $this->assertTrue($twenty->greaterThan($ten));
        $this->assertTrue($ten->lessThan($twenty));
        $this->assertTrue($ten->equals(Money::of(1000, 'USD')));
        $this->assertFalse($ten->equals(Money::of(1000, 'EUR')));
        $this->assertTrue($ten->lessThanOrEqual(Money::of(1000, 'USD')));
    }

    public function test_summing_an_empty_list_yields_a_typed_zero(): void
    {
        $total = Money::sum([], 'EUR');

        $this->assertTrue($total->isZero());
        $this->assertSame('EUR', $total->currency);
    }

    public function test_it_rejects_an_invalid_currency_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::of(100, 'DOLLARS');
    }

    public function test_it_rejects_unparseable_decimal_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::fromDecimal('1,299.00', 'USD');
    }
}
