<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Guests\Models\Guest;
use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price, quoted at a moment, honoured until it expires.
 *
 * The reason this is a stored record rather than a recomputation is that a
 * guest who was shown a price and comes back an hour later to pay must be
 * charged what they were shown. Rates change — a rule starts, a promotion
 * ends, a neighbouring booking makes the night scarce — and re-pricing at the
 * moment of payment would mean the number on the confirmation screen and the
 * number on the card differ.
 *
 * `breakdown` holds the full itemised explanation exactly as it was produced,
 * so "why is it this much" is answerable months later even after every rule
 * behind it has been edited.
 */
class Quote extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'listing_id', 'property_id', 'guest_id', 'reference',
        'check_in_date', 'check_out_date', 'nights',
        'adults', 'children', 'infants', 'pets',
        'currency', 'accommodation_total', 'fees_total', 'taxes_total',
        'discounts_total', 'grand_total', 'breakdown',
        'channel', 'promotion_id', 'rate_plan_id', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'check_in_date' => 'immutable_date',
            'check_out_date' => 'immutable_date',
            'nights' => 'integer',
            'adults' => 'integer',
            'children' => 'integer',
            'infants' => 'integer',
            'pets' => 'integer',
            'accommodation_total' => 'integer',
            'fees_total' => 'integer',
            'taxes_total' => 'integer',
            'discounts_total' => 'integer',
            'grand_total' => 'integer',
            'breakdown' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'converted_reservation_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now())
            ->whereNull('converted_reservation_id');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    public function grandTotal(): Money
    {
        return Money::of((int) $this->grand_total, $this->currency);
    }

    public function accommodationTotal(): Money
    {
        return Money::of((int) $this->accommodation_total, $this->currency);
    }

    public function feesTotal(): Money
    {
        return Money::of((int) $this->fees_total, $this->currency);
    }

    public function taxesTotal(): Money
    {
        return Money::of((int) $this->taxes_total, $this->currency);
    }

    public function discountsTotal(): Money
    {
        return Money::of((int) $this->discounts_total, $this->currency);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isConverted(): bool
    {
        return $this->converted_reservation_id !== null;
    }

    /**
     * Whether this quote can still be booked at the price it states.
     */
    public function isHonourable(): bool
    {
        return ! $this->hasExpired() && ! $this->isConverted();
    }
}
