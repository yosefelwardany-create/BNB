<?php

declare(strict_types=1);

namespace App\Domain\Reservations\Models;

use App\Domain\Properties\Models\Unit;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One night of a stay, at the rate that applied to it.
 *
 * Storing the breakdown rather than only a total is what allows revenue to be
 * recognised night by night, ADR and RevPAR to be computed without dividing a
 * total by a guess, and a rate change mid-stay to be represented honestly.
 *
 * `pricing_trace` records how the rate was reached, so any price a guest
 * questions can be explained rather than asserted.
 */
class ReservationNight extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'reservation_id',
        'stay_date',
        'rate_amount',
        'currency',
        'unit_id',
        'pricing_trace',
        'recognised_at',
    ];

    protected function casts(): array
    {
        return [
            'stay_date' => 'immutable_date',
            'pricing_trace' => 'array',
            'recognised_at' => 'immutable_datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function rate(): Money
    {
        return Money::of((int) $this->rate_amount, $this->currency);
    }

    /** Nights whose revenue has not yet been posted to the ledger. */
    public function scopeUnrecognised(Builder $query): Builder
    {
        return $query->whereNull('recognised_at');
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->where('stay_date', '>=', $from)->where('stay_date', '<', $to);
    }
}
