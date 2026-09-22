<?php

declare(strict_types=1);

namespace App\Domain\Listings\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Listings\Models\Listing;
use Illuminate\Database\Eloquent\Model;

/**
 * The listing is on sale. Channel adapters publish it and the availability
 * engine begins maintaining its calendar.
 */
class ListingPublished extends AbstractDomainEvent
{
    public const NAME = 'listing.published';

    public function __construct(public readonly Listing $listing)
    {
        parent::__construct();
    }

    public function subject(): ?Model
    {
        return $this->listing;
    }

    public function payload(): array
    {
        return [
            'listing_id' => $this->listing->getKey(),
            'property_id' => $this->listing->property_id,
            'title' => $this->listing->displayTitle(),
            'inventory_scope' => $this->listing->inventoryScope(),
            'currency' => $this->listing->currency,
        ];
    }
}
