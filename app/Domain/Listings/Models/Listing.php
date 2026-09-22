<?php

declare(strict_types=1);

namespace App\Domain\Listings\Models;

use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Properties\Enums\ListingStatus;
use App\Domain\Properties\Models\Amenity;
use App\Domain\Properties\Models\CancellationPolicy;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Properties\Models\Unit;
use App\Domain\Properties\Models\UnitType;
use App\Domain\Users\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An offer to sell nights in a property.
 *
 * Distinct from the property itself because one property routinely carries
 * several offers: the whole flat, two private rooms, a long-stay variant with
 * a different minimum stay. A listing is also the unit a channel maps to.
 *
 * Content and commercial fields are *nullable overrides*: null means "use the
 * property's value". That way fixing a typo on the property fixes every
 * listing that has not deliberately said something different, and it is always
 * obvious which fields a listing has taken ownership of.
 *
 * @property ListingStatus $status
 */
class Listing extends BaseModel
{
    use Auditable, BelongsToOrganization, HasFactory, SoftDeletes;

    /**
     * Fields that fall back to the property when the listing leaves them null.
     *
     * @var list<string>
     */
    public const INHERITED_FIELDS = [
        'summary', 'description', 'space_description', 'neighbourhood_description',
        'transit_description', 'house_rules', 'check_in_instructions',
        'check_out_instructions', 'max_occupancy', 'bedrooms', 'bathrooms', 'beds',
        'base_rate', 'cleaning_fee', 'extra_guest_fee', 'extra_guest_after',
        'minimum_nights', 'maximum_nights', 'check_in_time', 'check_out_time',
        'instant_book', 'cancellation_policy_id',
    ];

    protected $fillable = [
        'organization_id',
        'property_id',
        'unit_type_id',
        'unit_id',
        'name',
        'slug',
        'status',
        'is_primary',
        'title',
        'summary',
        'description',
        'space_description',
        'neighbourhood_description',
        'transit_description',
        'house_rules',
        'check_in_instructions',
        'check_out_instructions',
        'max_occupancy',
        'bedrooms',
        'bathrooms',
        'beds',
        'currency',
        'base_rate',
        'cleaning_fee',
        'extra_guest_fee',
        'extra_guest_after',
        'minimum_nights',
        'maximum_nights',
        'advance_notice_hours',
        'booking_window_days',
        'cancellation_policy_id',
        'rate_plan_id',
        'check_in_time',
        'check_out_time',
        'instant_book',
        'settings',
        'published_at',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class,
            'is_primary' => 'boolean',
            'instant_book' => 'boolean',
            'bathrooms' => 'decimal:1',
            'settings' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => 'draft',
        'is_primary' => false,
    ];

    protected static function booted(): void
    {
        static::saving(function (Listing $listing): void {
            if (blank($listing->slug)) {
                $listing->slug = Str::slug($listing->name).'-'.Str::lower(Str::random(5));
            }
        });
    }

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

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function cancellationPolicy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class, 'cancellation_policy_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ListingPhoto::class)->orderBy('position');
    }

    public function amenityOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'amenity_listing')
            ->withPivot(['is_excluded', 'value'])
            ->withTimestamps();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ListingVersion::class)->orderByDesc('version');
    }

    public function channelListings(): HasMany
    {
        return $this->hasMany(ChannelListing::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ListingStatus::Published->value);
    }

    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('status', ListingStatus::Published->value)
            ->whereHas('property', fn (Builder $q) => $q->where('status', 'active'));
    }

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    // ------------------------------------------------------------------
    // Inheritance
    // ------------------------------------------------------------------

    /**
     * Read an attribute, falling back to the property when the listing has not
     * overridden it.
     */
    public function resolved(string $field): mixed
    {
        $own = $this->getAttribute($field);

        if ($own !== null) {
            return $own;
        }

        if (! in_array($field, self::INHERITED_FIELDS, true)) {
            return null;
        }

        return $this->property?->getAttribute($field);
    }

    /**
     * The guest-facing title. Falls back to the property name, never to the
     * internal listing label.
     */
    public function displayTitle(): string
    {
        return $this->title ?: ($this->property?->name ?? $this->name);
    }

    /**
     * Every resolved field, which is what a channel adapter publishes and the
     * booking engine renders.
     *
     * @return array<string, mixed>
     */
    public function resolvedAttributes(): array
    {
        $resolved = ['title' => $this->displayTitle()];

        foreach (self::INHERITED_FIELDS as $field) {
            $resolved[$field] = $this->resolved($field);
        }

        return $resolved;
    }

    /**
     * Which fields this listing has deliberately taken ownership of.
     *
     * @return list<string>
     */
    public function overriddenFields(): array
    {
        $overridden = [];

        foreach (self::INHERITED_FIELDS as $field) {
            if ($this->getAttribute($field) !== null) {
                $overridden[] = $field;
            }
        }

        return $overridden;
    }

    /**
     * The amenities a guest actually gets: the property's set, minus those the
     * listing excludes, plus those it adds.
     *
     * @return list<Amenity>
     */
    public function effectiveAmenities(): array
    {
        $amenities = [];

        foreach ($this->property?->amenities ?? [] as $amenity) {
            $amenities[$amenity->getKey()] = $amenity;
        }

        foreach ($this->amenityOverrides as $amenity) {
            if ($amenity->pivot->is_excluded) {
                unset($amenities[$amenity->getKey()]);

                continue;
            }

            $amenities[$amenity->getKey()] = $amenity;
        }

        return array_values($amenities);
    }

    /**
     * The photos to show, in order. A listing with no explicit selection
     * shows the property's photos as the property orders them.
     *
     * @return list<PropertyPhoto>
     */
    public function effectivePhotos(): array
    {
        if ($this->photos->isEmpty()) {
            return ($this->property?->photos ?? collect())->all();
        }

        return $this->photos
            ->reject(fn (ListingPhoto $photo): bool => (bool) $photo->is_hidden)
            ->sortBy('position')
            ->map(fn (ListingPhoto $photo) => $photo->propertyPhoto)
            ->filter()
            ->values()
            ->all();
    }

    // ------------------------------------------------------------------
    // Money
    // ------------------------------------------------------------------

    public function baseRate(): Money
    {
        return Money::of((int) ($this->resolved('base_rate') ?? 0), $this->currency);
    }

    public function cleaningFee(): Money
    {
        return Money::of((int) ($this->resolved('cleaning_fee') ?? 0), $this->currency);
    }

    public function extraGuestFee(): Money
    {
        return Money::of((int) ($this->resolved('extra_guest_fee') ?? 0), $this->currency);
    }

    public function minimumNights(): int
    {
        return max(1, (int) ($this->resolved('minimum_nights') ?? 1));
    }

    public function maximumNights(): ?int
    {
        $value = $this->resolved('maximum_nights');

        return $value === null ? null : (int) $value;
    }

    public function maxOccupancy(): int
    {
        return max(1, (int) ($this->resolved('max_occupancy') ?? 1));
    }

    public function isBookable(): bool
    {
        return $this->status->isBookable() && ($this->property?->isBookable() ?? false);
    }

    /**
     * What inventory this listing sells: the whole property, a unit type, or
     * one specific unit. The availability engine branches on this.
     */
    public function inventoryScope(): string
    {
        return match (true) {
            $this->unit_id !== null => 'unit',
            $this->unit_type_id !== null => 'unit_type',
            default => 'property',
        };
    }
}
