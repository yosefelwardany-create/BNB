<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Properties\Models\Property;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lodging tax.
 *
 * Real lodging tax is layered and conditional: a city tax of a fixed amount
 * per guest per night, capped at seven nights and waived for under-12s, sitting
 * alongside a percentage VAT that may or may not be charged on the city tax as
 * well. Every one of those shapes is expressible here, because approximating
 * them produces a remittance figure that is quietly wrong.
 *
 * `channels_collecting` matters commercially: where a channel collects and
 * remits a tax itself, the manager must not also record it as owed.
 */
class TaxRule extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const PERCENT = 'percent';

    public const FIXED_PER_STAY = 'fixed_per_stay';

    public const FIXED_PER_NIGHT = 'fixed_per_night';

    public const FIXED_PER_GUEST = 'fixed_per_guest';

    public const FIXED_PER_GUEST_PER_NIGHT = 'fixed_per_guest_per_night';

    protected $fillable = [
        'organization_id', 'name', 'code', 'description', 'calculation',
        'rate', 'amount', 'currency',
        'applies_to_accommodation', 'applies_to_fees', 'applies_to_fee_codes',
        'compounds_on_taxes',
        'maximum_nights', 'exempt_after_nights', 'exempt_guest_age_under', 'maximum_amount',
        'property_id', 'portfolio_id', 'country_code', 'region', 'city',
        'channels_collecting', 'channels',
        'effective_from', 'effective_to', 'priority', 'is_active',
        'remittance_reference',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'applies_to_accommodation' => 'boolean',
            'applies_to_fees' => 'boolean',
            'applies_to_fee_codes' => 'array',
            'compounds_on_taxes' => 'boolean',
            'channels_collecting' => 'array',
            'channels' => 'array',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    protected $attributes = [
        'applies_to_accommodation' => true,
        'applies_to_fees' => false,
        'compounds_on_taxes' => false,
        'priority' => 100,
        'is_active' => true,
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Taxes that could apply to a property, in the order they must be applied.
     *
     * Ordering matters because a compounding tax charges on a base that
     * already includes the taxes applied before it.
     */
    public function scopeForProperty(Builder $query, Property $property): Builder
    {
        return $query->active()
            ->where(fn (Builder $q) => $q->whereNull('property_id')->orWhere('property_id', $property->getKey()))
            ->where(fn (Builder $q) => $q->whereNull('portfolio_id')->orWhere('portfolio_id', $property->portfolio_id))
            ->where(fn (Builder $q) => $q->whereNull('country_code')->orWhere('country_code', $property->country_code))
            ->orderBy('priority')
            ->orderBy('id');
    }

    /**
     * Whether the given channel collects and remits this tax itself.
     */
    public function isCollectedBy(string $channel): bool
    {
        return in_array($channel, $this->channels_collecting ?? [], true);
    }

    public function appliesToChannel(string $channel): bool
    {
        $channels = $this->channels;

        return $channels === null || $channels === [] || in_array($channel, $channels, true);
    }

    public function isInForce(\DateTimeInterface $stayDate): bool
    {
        $date = CarbonImmutable::parse($stayDate)->startOfDay();

        if ($this->effective_from !== null && $date->lt($this->effective_from)) {
            return false;
        }

        return ! ($this->effective_to !== null && $date->gt($this->effective_to));
    }

    public function isPercentage(): bool
    {
        return $this->calculation === self::PERCENT;
    }
}
