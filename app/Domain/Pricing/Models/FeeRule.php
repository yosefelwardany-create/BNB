<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\Property;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A charge on top of the nightly rate.
 *
 * `charge_basis` is what makes the same model serve a flat cleaning fee, a
 * per-night parking charge and a per-guest-per-night resort fee without any of
 * them being a special case in the pricing engine.
 */
class FeeRule extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const PER_STAY = 'per_stay';

    public const PER_NIGHT = 'per_night';

    public const PER_GUEST = 'per_guest';

    public const PER_GUEST_PER_NIGHT = 'per_guest_per_night';

    public const PER_PET = 'per_pet';

    public const PER_PET_PER_NIGHT = 'per_pet_per_night';

    public const PERCENT_OF_ACCOMMODATION = 'percent_of_accommodation';

    protected $fillable = [
        'organization_id', 'name', 'code', 'description', 'kind', 'charge_basis',
        'amount', 'percentage', 'currency',
        'applies_after_guests', 'applies_after_nights', 'maximum_units',
        'property_id', 'listing_id', 'portfolio_id', 'channels',
        'effective_from', 'effective_to',
        'is_taxable', 'is_refundable', 'is_optional', 'include_in_displayed_rate',
        'is_active', 'position', 'revenue_account_key',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:4',
            'channels' => 'array',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'is_taxable' => 'boolean',
            'is_refundable' => 'boolean',
            'is_optional' => 'boolean',
            'include_in_displayed_rate' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected $attributes = [
        'kind' => 'custom',
        'is_taxable' => true,
        'is_refundable' => true,
        'is_optional' => false,
        'include_in_displayed_rate' => false,
        'is_active' => true,
        'position' => 0,
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForListing(Builder $query, Listing $listing): Builder
    {
        $property = $listing->property;

        return $query->active()
            ->where(fn (Builder $q) => $q->whereNull('property_id')->orWhere('property_id', $listing->property_id))
            ->where(fn (Builder $q) => $q->whereNull('listing_id')->orWhere('listing_id', $listing->getKey()))
            ->where(fn (Builder $q) => $q->whereNull('portfolio_id')->orWhere('portfolio_id', $property?->portfolio_id))
            ->orderBy('position')
            ->orderBy('id');
    }

    public function baseAmount(): Money
    {
        return Money::of((int) $this->amount, $this->currency);
    }

    public function appliesToChannel(string $channel): bool
    {
        $channels = $this->channels;

        return $channels === null || $channels === [] || in_array($channel, $channels, true);
    }

    public function isInForce(\DateTimeInterface $date): bool
    {
        $day = \Carbon\CarbonImmutable::parse($date)->startOfDay();

        if ($this->effective_from !== null && $day->lt($this->effective_from)) {
            return false;
        }

        return ! ($this->effective_to !== null && $day->gt($this->effective_to));
    }
}
