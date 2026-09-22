<?php

declare(strict_types=1);

namespace App\Domain\Listings\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Listings\Models\Listing;
use Illuminate\Database\Eloquent\Model;

/**
 * Listing content changed.
 *
 * `changedFields` lets consumers decide whether the change is worth acting on:
 * a rate change needs pushing to channels immediately, a description tweak can
 * be batched into the next content sync.
 */
class ListingUpdated extends AbstractDomainEvent
{
    public const NAME = 'listing.updated';

    /**
     * @param  list<string>  $changedFields
     */
    public function __construct(
        public readonly Listing $listing,
        public readonly array $changedFields = [],
    ) {
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
            'changed_fields' => $this->changedFields,
            'affects_pricing' => (bool) array_intersect($this->changedFields, [
                'base_rate', 'cleaning_fee', 'extra_guest_fee', 'extra_guest_after',
            ]),
            'affects_restrictions' => (bool) array_intersect($this->changedFields, [
                'minimum_nights', 'maximum_nights',
            ]),
        ];
    }
}
