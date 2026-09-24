<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Models;

use App\Support\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One day's rate for one currency pair.
 *
 * Not tenant-scoped: a rate is a fact about the world rather than about a
 * customer, and every organization reads the same numbers.
 */
class ExchangeRate extends BaseModel
{
    protected $table = 'exchange_rates';

    protected $fillable = [
        'base_currency', 'quote_currency', 'rate_date', 'rate', 'source',
    ];

    protected function casts(): array
    {
        return [
            'rate_date' => 'immutable_date',
            // A string, deliberately: cast to float here and the precision the
            // column exists to preserve is lost on the way out of the database.
            'rate' => 'decimal:10',
        ];
    }

    /**
     * The most recent rate on or before a date.
     *
     * On or before, because markets close: a stay starting on a Sunday converts
     * at Friday's rate, which is what every accountant expects and what refusing
     * to answer would make impossible.
     */
    public function scopeAsOf(Builder $query, CarbonImmutable $on): Builder
    {
        return $query->where('rate_date', '<=', $on->toDateString())
            ->orderByDesc('rate_date');
    }

    public function scopeForPair(Builder $query, string $from, string $to): Builder
    {
        return $query->where('base_currency', strtoupper($from))
            ->where('quote_currency', strtoupper($to));
    }
}
