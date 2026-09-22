<?php

declare(strict_types=1);

namespace App\Domain\Upsells\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something a guest can buy on top of the stay.
 *
 * `lead_time_hours` is the field that keeps this honest. An early check-in
 * ordered an hour before arrival is a promise the housekeeping team cannot
 * keep, and selling it anyway converts a small extra into a complaint. The
 * same goes for `daily_capacity`: one airport transfer can be driven at a
 * time, and a product with no cap will cheerfully sell four.
 */
class UpsellProduct extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const PER_STAY = 'per_stay';

    public const PER_NIGHT = 'per_night';

    public const PER_GUEST = 'per_guest';

    public const PER_UNIT = 'per_unit';

    protected $fillable = [
        'organization_id', 'name', 'code', 'description', 'kind',
        'price', 'currency', 'charge_basis', 'is_taxable', 'property_ids',
        'lead_time_hours', 'max_quantity', 'daily_capacity',
        'requires_approval', 'is_active', 'position', 'image_path', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'is_taxable' => 'boolean',
            'property_ids' => 'array',
            'lead_time_hours' => 'integer',
            'max_quantity' => 'integer',
            'daily_capacity' => 'integer',
            'requires_approval' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected $attributes = [
        'kind' => 'other',
        'charge_basis' => self::PER_STAY,
        'is_taxable' => true,
        'lead_time_hours' => 0,
        'max_quantity' => 1,
        'requires_approval' => false,
        'is_active' => true,
        'position' => 0,
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(UpsellOrder::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Products offered at a property.
     *
     * An empty property list means everywhere, so those must be included or a
     * property-filtered menu looks emptier than it is.
     */
    public function scopeForProperty(Builder $query, string $propertyId): Builder
    {
        return $query->where(function (Builder $q) use ($propertyId): void {
            $q->whereNull('property_ids')
                ->orWhereJsonLength('property_ids', 0)
                ->orWhereJsonContains('property_ids', $propertyId);
        });
    }

    public function price(): Money
    {
        return Money::of((int) $this->price, $this->currency);
    }

    /**
     * What a given order would cost.
     *
     * The multiplier depends on the basis, and getting it wrong is the
     * difference between an 80 clean and a 560 one.
     */
    public function priceFor(int $quantity, int $nights = 1, int $guests = 1): Money
    {
        $multiplier = match ($this->charge_basis) {
            self::PER_NIGHT => max(1, $nights),
            self::PER_GUEST => max(1, $guests),
            default => 1,
        };

        return $this->price()->multiply($multiplier * max(1, $quantity));
    }

    /**
     * Whether there is still time to deliver this before the service date.
     */
    public function canBeOrderedFor(\DateTimeInterface $serviceAt, ?\DateTimeInterface $now = null): bool
    {
        $now ??= now();

        $hoursAway = ($serviceAt->getTimestamp() - $now->getTimestamp()) / 3600;

        return $hoursAway >= (int) $this->lead_time_hours;
    }
}
