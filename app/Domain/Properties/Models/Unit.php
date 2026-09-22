<?php

declare(strict_types=1);

namespace App\Domain\Properties\Models;

use App\Domain\Availability\Models\CalendarBlock;
use App\Domain\Properties\Enums\UnitStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasCustomFields;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A specific, individually bookable space: flat 3B, room 204, the cabin by
 * the lake.
 *
 * Attributes fall back through unit → unit type → property, so a building of
 * forty identical studios is configured once and only the exceptions are
 * recorded per unit.
 *
 * Parent/child relationships model a space that can be sold whole or split:
 * a two-bedroom flat also offered as two lockable rooms. Booking either side
 * makes the other unsellable, which the availability engine enforces by
 * treating a unit and its relatives as one block of inventory.
 *
 * @property UnitStatus $status
 */
class Unit extends BaseModel
{
    use Auditable, BelongsToOrganization, HasCustomFields, HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'property_id',
        'unit_type_id',
        'parent_unit_id',
        'name',
        'code',
        'floor',
        'status',
        'bedrooms',
        'bathrooms',
        'beds',
        'max_occupancy',
        'base_rate',
        'access_notes',
        'internal_notes',
        'settings',
        'is_bookable',
        'position',
    ];

    protected $hidden = ['access_notes'];

    protected function casts(): array
    {
        return [
            'status' => UnitStatus::class,
            'bathrooms' => 'decimal:1',
            'is_bookable' => 'boolean',
            'settings' => 'array',
            'access_notes' => 'encrypted',
        ];
    }

    protected $attributes = [
        'status' => 'available',
        'is_bookable' => true,
        'position' => 0,
    ];

    // ------------------------------------------------------------------
    // Relationships
    // ------------------------------------------------------------------

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unitType(): BelongsTo
    {
        return $this->belongsTo(UnitType::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_unit_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_unit_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function calendarBlocks(): HasMany
    {
        return $this->hasMany(CalendarBlock::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(PropertyPhoto::class)->orderBy('position');
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('is_bookable', true)
            ->where('status', UnitStatus::Available->value);
    }

    public function scopeForProperty(Builder $query, string $propertyId): Builder
    {
        return $query->where('property_id', $propertyId);
    }

    // ------------------------------------------------------------------
    // Inherited attributes
    // ------------------------------------------------------------------

    /**
     * Occupancy, falling back to the unit type and then the property.
     */
    public function maxOccupancy(): int
    {
        return (int) ($this->max_occupancy
            ?? $this->unitType?->max_occupancy
            ?? $this->property?->max_occupancy
            ?? 1);
    }

    public function bedrooms(): int
    {
        return (int) ($this->bedrooms ?? $this->unitType?->bedrooms ?? $this->property?->bedrooms ?? 0);
    }

    public function beds(): int
    {
        return (int) ($this->beds ?? $this->unitType?->beds ?? $this->property?->beds ?? 0);
    }

    public function bathrooms(): float
    {
        return (float) ($this->bathrooms ?? $this->unitType?->bathrooms ?? $this->property?->bathrooms ?? 0);
    }

    /**
     * The nightly base rate for this unit, inheriting upwards when it has no
     * rate of its own.
     */
    public function baseRate(): Money
    {
        $currency = $this->property?->currency ?? 'USD';

        $amount = $this->base_rate
            ?? $this->unitType?->base_rate
            ?? $this->property?->base_rate
            ?? 0;

        return Money::of((int) $amount, $currency);
    }

    public function isSellable(): bool
    {
        return $this->is_bookable && $this->status->isSellable();
    }

    public function displayName(): string
    {
        return $this->code ? sprintf('%s (%s)', $this->name, $this->code) : $this->name;
    }

    /**
     * Units whose availability is bound to this one: its parent and every
     * descendant.
     *
     * Selling a two-bedroom flat must make both of its rooms unsellable, and
     * selling either room must make the whole flat unsellable. The availability
     * engine asks for this set rather than assuming a flat hierarchy.
     *
     * @return Collection<int, self>
     */
    public function conflictingUnits(): Collection
    {
        $ids = [];

        // Ancestors. Walked by explicit query rather than through the
        // relationship so this works on a unit loaded without eager loading,
        // which is how the availability engine receives them.
        $parentId = $this->parent_unit_id;
        $guard = 0;

        while ($parentId !== null && $guard++ < 20) {
            $parent = self::query()->select(['id', 'parent_unit_id'])->find($parentId);

            if ($parent === null) {
                break;
            }

            $ids[] = $parent->getKey();
            $parentId = $parent->parent_unit_id;
        }

        // Descendants
        $frontier = [$this->getKey()];

        while ($frontier !== []) {
            $children = self::query()
                ->whereIn('parent_unit_id', $frontier)
                ->pluck('id')
                ->all();

            if ($children === []) {
                break;
            }

            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        if ($ids === []) {
            return self::query()->whereRaw('1 = 0')->get();
        }

        return self::query()->whereIn('id', array_unique($ids))->get();
    }

    /**
     * Identifiers of every unit whose availability this unit affects,
     * including itself.
     *
     * @return list<string>
     */
    public function occupancyGroupIds(): array
    {
        return array_values(array_unique(array_merge(
            [$this->getKey()],
            $this->conflictingUnits()->pluck('id')->all(),
        )));
    }
}
