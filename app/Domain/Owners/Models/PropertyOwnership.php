<?php

declare(strict_types=1);

namespace App\Domain\Owners\Models;

use App\Domain\Properties\Models\Property;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One owner's share in one property, over a period.
 *
 * Fractional and joint ownership are ordinary in this industry, and properties
 * change hands mid-year, so a share carries dates. Owner statements attribute
 * each night's revenue using the shares in force on that night rather than the
 * shares that happen to exist when the statement is run.
 */
class PropertyOwnership extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'property_id', 'owner_id',
        'ownership_percentage', 'is_primary', 'starts_on', 'ends_on', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'ownership_percentage' => 'decimal:4',
            'is_primary' => 'boolean',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    protected $attributes = [
        'ownership_percentage' => 100,
        'is_primary' => false,
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    /**
     * Shares in force on a given date.
     */
    public function scopeInForceOn(Builder $query, string|CarbonImmutable $date): Builder
    {
        $day = $date instanceof CarbonImmutable ? $date->toDateString() : $date;

        return $query->where(fn (Builder $q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $day))
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $day));
    }

    public function isInForceOn(CarbonImmutable $date): bool
    {
        if ($this->starts_on !== null && $date->lt($this->starts_on)) {
            return false;
        }

        return ! ($this->ends_on !== null && $date->gt($this->ends_on));
    }

    public function share(): float
    {
        return (float) $this->ownership_percentage;
    }
}
