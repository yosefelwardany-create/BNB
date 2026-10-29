<?php

declare(strict_types=1);

namespace App\Domain\Websites\Models;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One listing shown on one website.
 *
 * Carries the listing's organization, so a brand site that mixes client
 * accounts always knows whose account a quote or booking belongs in without
 * reading the listing through a tenant scope first.
 *
 * @property string $website_id
 * @property string $listing_id
 * @property string $organization_id
 * @property int $position
 * @property bool $is_featured
 */
class WebsiteListing extends BaseModel
{
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_featured' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
