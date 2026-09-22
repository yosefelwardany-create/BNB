<?php

declare(strict_types=1);

namespace App\Domain\Listings\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Listings\Models\Listing;
use Illuminate\Database\Eloquent\Model;

/**
 * The listing has been paused or archived. Channels are told to stop selling
 * it; existing reservations are untouched.
 */
class ListingUnpublished extends AbstractDomainEvent
{
    public const NAME = 'listing.unpublished';

    public function __construct(
        public readonly Listing $listing,
        public readonly ?string $reason = null,
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
            'status' => $this->listing->status->value,
            'reason' => $this->reason,
        ];
    }
}
