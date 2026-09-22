<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Pricing\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Quote
 */
class QuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,

            'listing_id' => $this->listing_id,
            'property_id' => $this->property_id,
            'guest_id' => $this->guest_id,

            'check_in_date' => $this->check_in_date?->toDateString(),
            'check_out_date' => $this->check_out_date?->toDateString(),
            'nights' => (int) $this->nights,
            'adults' => (int) $this->adults,
            'children' => (int) $this->children,
            'infants' => (int) $this->infants,
            'pets' => (int) $this->pets,

            'currency' => $this->currency,
            'accommodation_total' => $this->accommodationTotal()->jsonSerialize(),
            'fees_total' => $this->feesTotal()->jsonSerialize(),
            'taxes_total' => $this->taxesTotal()->jsonSerialize(),
            'discounts_total' => $this->discountsTotal()->jsonSerialize(),
            'grand_total' => $this->grandTotal()->jsonSerialize(),

            // The itemisation exactly as it was produced. Every rule behind
            // these numbers can be edited tomorrow; this explanation does not
            // change with them.
            'breakdown' => $this->breakdown,

            'channel' => $this->channel,
            'rate_plan_id' => $this->rate_plan_id,
            'promotion_id' => $this->promotion_id,

            'expires_at' => $this->expires_at?->toIso8601String(),
            'has_expired' => $this->hasExpired(),
            'converted_reservation_id' => $this->converted_reservation_id,

            // Whether this price can still be booked. Reported rather than
            // left for the client to derive from two timestamps.
            'is_honourable' => $this->isHonourable(),

            'listing' => new ListingResource($this->whenLoaded('listing')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
