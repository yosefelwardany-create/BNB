<?php

declare(strict_types=1);

namespace App\Domain\Listings\Models;

use App\Domain\Properties\Models\PropertyPhoto;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A property photo's placement within one listing.
 *
 * The file is not duplicated: this row only records order, an optional
 * listing-specific caption, and whether the photo is hidden from this
 * particular offer.
 */
class ListingPhoto extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id',
        'listing_id',
        'property_photo_id',
        'caption',
        'position',
        'is_hidden',
    ];

    protected function casts(): array
    {
        return ['is_hidden' => 'boolean'];
    }

    protected $attributes = [
        'position' => 0,
        'is_hidden' => false,
    ];

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function propertyPhoto(): BelongsTo
    {
        return $this->belongsTo(PropertyPhoto::class, 'property_photo_id');
    }

    /** The listing's own caption, or the property photo's. */
    public function effectiveCaption(): ?string
    {
        return $this->caption ?: $this->propertyPhoto?->caption;
    }
}
