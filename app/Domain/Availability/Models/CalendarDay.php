<?php

declare(strict_types=1);

namespace App\Domain\Availability\Models;

use App\Domain\Listings\Models\Listing;
use App\Domain\Users\Models\User;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A deliberate per-date override on a listing's calendar.
 *
 * Rows exist only where an operator (or a revenue rule) has said something
 * different from the defaults, so an untouched two-year horizon costs nothing
 * to store. A missing row means "no override", not "unavailable".
 */
class CalendarDay extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'listing_id',
        'calendar_date',
        'rate_override',
        'minimum_nights',
        'maximum_nights',
        'closed_to_arrival',
        'closed_to_departure',
        'is_blocked',
        'note',
        'updated_by_id',
    ];

    protected function casts(): array
    {
        return [
            'calendar_date' => 'immutable_date',
            'closed_to_arrival' => 'boolean',
            'closed_to_departure' => 'boolean',
            'is_blocked' => 'boolean',
        ];
    }

    protected $attributes = [
        'closed_to_arrival' => false,
        'closed_to_departure' => false,
        'is_blocked' => false,
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->where('calendar_date', '>=', $from)->where('calendar_date', '<', $to);
    }

    /**
     * Whether the row still says anything. Used to prune overrides that have
     * been reset to defaults rather than leaving inert rows behind.
     */
    public function isEmpty(): bool
    {
        return $this->rate_override === null
            && $this->minimum_nights === null
            && $this->maximum_nights === null
            && ! $this->closed_to_arrival
            && ! $this->closed_to_departure
            && ! $this->is_blocked
            && blank($this->note);
    }
}
