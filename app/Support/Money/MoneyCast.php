<?php

declare(strict_types=1);

namespace App\Support\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a pair of database columns (an integer minor-unit column and a
 * currency column) into a Money value object.
 *
 * Usage:  'total' => MoneyCast::class.':total_amount,currency'
 *
 * When the currency column is omitted the model's `currency` attribute is
 * used, which is the common case.
 *
 * @implements CastsAttributes<Money|null, Money|null>
 */
final class MoneyCast implements CastsAttributes
{
    public function __construct(
        private readonly string $amountColumn = '',
        private readonly string $currencyColumn = 'currency',
    ) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        $amountColumn = $this->amountColumn !== '' ? $this->amountColumn : $key;

        $amount = $attributes[$amountColumn] ?? null;

        if ($amount === null) {
            return null;
        }

        $currency = $attributes[$this->currencyColumn] ?? null;

        if ($currency === null) {
            return null;
        }

        return Money::of((int) $amount, (string) $currency);
    }

    /**
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $amountColumn = $this->amountColumn !== '' ? $this->amountColumn : $key;

        if ($value === null) {
            return [$amountColumn => null];
        }

        if (! $value instanceof Money) {
            // Accept a raw integer as "minor units in the model's currency".
            return [$amountColumn => (int) $value];
        }

        $existingCurrency = $attributes[$this->currencyColumn] ?? null;

        if ($existingCurrency !== null && strtoupper((string) $existingCurrency) !== $value->currency) {
            throw new CurrencyMismatchException(sprintf(
                'Refusing to write a %s amount into [%s] which is denominated in %s.',
                $value->currency,
                $amountColumn,
                strtoupper((string) $existingCurrency),
            ));
        }

        return [
            $amountColumn => $value->minorUnits,
            $this->currencyColumn => $value->currency,
        ];
    }
}
