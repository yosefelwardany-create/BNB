<?php

declare(strict_types=1);

namespace App\Domain\Properties\Models;

use App\Domain\Availability\Models\CalendarBlock;
use App\Domain\Listings\Models\Listing;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Models\PropertyOwnership;
use App\Domain\Properties\Enums\PropertyStatus;
use App\Domain\Properties\Enums\PropertyType;
use App\Domain\Properties\Enums\RentalKind;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Concerns\HasCustomFields;
use App\Support\Concerns\HasTags;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A physical property under management.
 *
 * Two things about this model matter more than the rest of its fields:
 *
 *  - It carries its own timezone and currency. Every scheduled action (a
 *    check-in reminder, a cleaning window, a cancellation cut-off) is computed
 *    in *this* timezone, never the server's and never the organization's,
 *    because a portfolio routinely spans several.
 *  - It is never hard-deleted once it has trading history. Archiving keeps
 *    reservations, ledger entries and owner statements resolvable.
 *
 * @property PropertyStatus $status
 * @property PropertyType $property_type
 * @property string $timezone
 * @property string $currency
 */
class Property extends BaseModel
{
    use Auditable, BelongsToOrganization, HasCustomFields, HasFactory, HasTags, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'portfolio_id',
        'complex_id',
        'name',
        'internal_name',
        'slug',
        'reference',
        'property_type',
        'rental_kind',
        'status',
        'is_multi_unit',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country_code',
        'neighbourhood',
        'latitude',
        'longitude',
        'timezone',
        'currency',
        'bedrooms',
        'bathrooms',
        'beds',
        'max_occupancy',
        'max_adults',
        'max_children',
        'max_infants',
        'max_pets',
        'size_value',
        'size_unit',
        'floor',
        'summary',
        'description',
        'space_description',
        'neighbourhood_description',
        'transit_description',
        'house_rules',
        'check_in_instructions',
        'check_out_instructions',
        'internal_notes',
        'check_in_time',
        'check_out_time',
        'check_in_until',
        'check_in_method',
        'wifi_network',
        'wifi_password',
        'door_code',
        'access_notes',
        'base_rate',
        'cleaning_fee',
        'security_deposit',
        'extra_guest_fee',
        'extra_guest_after',
        'minimum_nights',
        'maximum_nights',
        'cleaning_duration_minutes',
        'preparation_hours',
        'cancellation_policy_id',
        'instant_book',
        'settings',
        'created_by_id',
        'activated_at',
    ];

    /**
     * Access credentials are hidden from serialisation and encrypted at rest.
     */
    protected $hidden = [
        'wifi_password',
        'door_code',
    ];

    protected function casts(): array
    {
        return [
            'property_type' => PropertyType::class,
            'rental_kind' => RentalKind::class,
            'status' => PropertyStatus::class,
            'is_multi_unit' => 'boolean',
            'instant_book' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'bathrooms' => 'decimal:1',
            'settings' => 'array',
            'activated_at' => 'datetime',
            // Guest-facing secrets never sit in the database as plaintext.
            'wifi_network' => 'encrypted',
            'wifi_password' => 'encrypted',
            'door_code' => 'encrypted',
            'access_notes' => 'encrypted',
        ];
    }

    protected $attributes = [
        'status' => 'draft',
        'rental_kind' => 'entire_place',
        'is_multi_unit' => false,
        'instant_book' => false,
        'minimum_nights' => 1,
        'cleaning_duration_minutes' => 120,
        'preparation_hours' => 0,
    ];

    // ------------------------------------------------------------------
    // Relationships
    // ------------------------------------------------------------------

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function complex(): BelongsTo
    {
        return $this->belongsTo(Complex::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class)->orderBy('position')->orderBy('name');
    }

    public function unitTypes(): HasMany
    {
        return $this->hasMany(UnitType::class)->orderBy('position');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(PropertyPhoto::class)->orderBy('position');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(PropertyRoom::class)->orderBy('position');
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'amenity_property')
            ->withPivot(['value', 'notes'])
            ->withTimestamps();
    }

    public function cancellationPolicy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class, 'cancellation_policy_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function calendarBlocks(): HasMany
    {
        return $this->hasMany(CalendarBlock::class);
    }

    public function ownerships(): HasMany
    {
        return $this->hasMany(PropertyOwnership::class);
    }

    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(Owner::class, 'property_ownerships')
            ->withPivot(['ownership_percentage', 'is_primary', 'starts_on', 'ends_on'])
            ->withTimestamps();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PropertyStatus::Active->value);
    }

    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('status', PropertyStatus::Active->value);
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->whereIn('status', [
            PropertyStatus::Active->value,
            PropertyStatus::Inactive->value,
        ]);
    }

    /**
     * Constrain to the properties a member is allowed to see.
     *
     * Passing null means unrestricted, which is what an unrestricted member
     * and a platform administrator both produce.
     *
     * @param  list<string>|null  $propertyIds
     */
    public function scopeVisibleTo(Builder $query, ?array $propertyIds): Builder
    {
        if ($propertyIds === null) {
            return $query;
        }

        return $query->whereIn($query->getModel()->qualifyColumn('id'), $propertyIds);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }

        $like = '%'.str_replace('%', '\%', trim($term)).'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('name', 'ilike', $like)
                ->orWhere('internal_name', 'ilike', $like)
                ->orWhere('reference', 'ilike', $like)
                ->orWhere('address_line_1', 'ilike', $like)
                ->orWhere('city', 'ilike', $like);
        });
    }

    // ------------------------------------------------------------------
    // Behaviour
    // ------------------------------------------------------------------

    /**
     * The name staff see on rotas and reports.
     */
    public function displayName(): string
    {
        return $this->internal_name ?: $this->name;
    }

    public function baseRate(): Money
    {
        return Money::of((int) $this->base_rate, $this->currency);
    }

    public function cleaningFee(): Money
    {
        return Money::of((int) $this->cleaning_fee, $this->currency);
    }

    public function securityDeposit(): Money
    {
        return Money::of((int) $this->security_deposit, $this->currency);
    }

    public function extraGuestFee(): Money
    {
        return Money::of((int) $this->extra_guest_fee, $this->currency);
    }

    /**
     * "Now" in the property's own timezone.
     *
     * Everything time-sensitive about a property — whether today's check-in
     * window has opened, whether a cancellation is still free, when a cleaning
     * is due — must be decided against this, not against the server clock.
     */
    public function localNow(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone);
    }

    /**
     * Turn a local date and time at this property into an absolute instant.
     */
    public function localDateTime(string|CarbonImmutable $date, string $time): CarbonImmutable
    {
        $date = $date instanceof CarbonImmutable ? $date->toDateString() : $date;

        return CarbonImmutable::parse($date.' '.$time, $this->timezone);
    }

    /**
     * The absolute instant a guest may arrive on a given local date.
     */
    public function checkInAt(string|CarbonImmutable $date): CarbonImmutable
    {
        return $this->localDateTime($date, $this->formatTime($this->check_in_time, '15:00'));
    }

    /**
     * The absolute instant a guest must leave on a given local date.
     */
    public function checkOutAt(string|CarbonImmutable $date): CarbonImmutable
    {
        return $this->localDateTime($date, $this->formatTime($this->check_out_time, '11:00'));
    }

    public function isBookable(): bool
    {
        return $this->status->isBookable();
    }

    /**
     * Whether availability for this property is tracked per unit.
     *
     * True when the property has been marked multi-unit, or when it sells
     * parts of itself separately (private rooms).
     */
    public function tracksAvailabilityPerUnit(): bool
    {
        return $this->is_multi_unit || $this->rental_kind->isPartial();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    private function formatTime(mixed $value, string $fallback): string
    {
        if ($value === null) {
            return $fallback;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }

        return (string) $value;
    }
}
