<?php

declare(strict_types=1);

namespace App\Domain\Properties\Models;

use App\Domain\Listings\Models\Listing;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A facility or feature.
 *
 * Rows with a null organization_id form the platform catalogue, which is what
 * channel adapters map against. Organizations may add their own, but those do
 * not travel to channels because there is nothing to map them to.
 */
class Amenity extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'key',
        'name',
        'category',
        'icon',
        'is_highlight',
        'position',
    ];

    protected function casts(): array
    {
        return ['is_highlight' => 'boolean'];
    }

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'amenity_property')->withTimestamps();
    }

    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'amenity_listing')->withTimestamps();
    }

    /** The shared catalogue plus this organization's own additions. */
    public function scopeAvailableTo(Builder $query, ?string $organizationId): Builder
    {
        return $query->where(function (Builder $q) use ($organizationId): void {
            $q->whereNull('organization_id');

            if ($organizationId !== null) {
                $q->orWhere('organization_id', $organizationId);
            }
        });
    }

    public function scopePlatform(Builder $query): Builder
    {
        return $query->whereNull('organization_id');
    }

    /** Only platform amenities can be published to a channel. */
    public function isMappable(): bool
    {
        return $this->organization_id === null;
    }
}
