<?php

declare(strict_types=1);

namespace App\Domain\Properties\Models;

use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A class of interchangeable units, e.g. "One-bedroom sea view".
 *
 * Guests book the type; a specific unit is assigned nearer the stay. This is
 * what lets an aparthotel sell twenty identical studios as one listing without
 * committing to which studio anyone gets.
 */
class UnitType extends BaseModel
{
    use Auditable, BelongsToOrganization, HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'property_id',
        'name',
        'code',
        'description',
        'bedrooms',
        'bathrooms',
        'beds',
        'max_occupancy',
        'size_value',
        'base_rate',
        'position',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'bathrooms' => 'decimal:1',
            'is_active' => 'boolean',
        ];
    }

    protected $attributes = [
        'is_active' => true,
        'position' => 0,
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * How many units of this type can currently be sold. This is the
     * inventory count the availability engine works against.
     */
    public function sellableUnitCount(): int
    {
        return $this->units()
            ->where('is_bookable', true)
            ->where('status', 'available')
            ->count();
    }

    public function baseRate(string $currency): ?Money
    {
        return $this->base_rate === null ? null : Money::of((int) $this->base_rate, $currency);
    }
}
