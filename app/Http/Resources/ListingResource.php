<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Listings\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Listing $resource
 */
class ListingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $listing = $this->resource;

        return [
            'id' => $listing->getKey(),
            'property_id' => $listing->property_id,
            'unit_type_id' => $listing->unit_type_id,
            'unit_id' => $listing->unit_id,

            'name' => $listing->name,
            'slug' => $listing->slug,
            'status' => $listing->status->value,
            'is_primary' => $listing->is_primary,
            'is_bookable' => $listing->isBookable(),
            'inventory_scope' => $listing->inventoryScope(),

            'title' => $listing->displayTitle(),

            /*
             * The title column itself, which is usually null.
             *
             * `title` above is the *display* title and falls back to the
             * property's name, which is right for a guest and for a channel. It
             * is wrong to seed an edit form with, because a form showing
             * "Alfama Terrace" cannot be told apart from a listing that really
             * has that title — and the difference matters the moment somebody
             * renames the property and expects the listing to follow.
             *
             * Same idea as `resolved` and `overridden_fields` for every other
             * inheritable field; `title` needs its own entry only because it
             * inherits without being in `Listing::INHERITED_FIELDS`.
             */
            'own_title' => $listing->title,

            // `resolved` is what a guest or a channel actually sees after
            // inheritance; `overridden_fields` names what this listing has
            // taken ownership of, so the interface can show which values are
            // its own and which still follow the property.
            'resolved' => $this->resolvedContent($listing),
            'overridden_fields' => $listing->overriddenFields(),

            'currency' => $listing->currency,
            'pricing' => [
                'base_rate' => $listing->baseRate()->jsonSerialize(),
                'cleaning_fee' => $listing->cleaningFee()->jsonSerialize(),
                'extra_guest_fee' => $listing->extraGuestFee()->jsonSerialize(),
                'extra_guest_after' => $listing->resolved('extra_guest_after'),
                'minimum_nights' => $listing->minimumNights(),
                'maximum_nights' => $listing->maximumNights(),
                'advance_notice_hours' => $listing->advance_notice_hours,
                'booking_window_days' => $listing->booking_window_days,
            ],

            'cancellation_policy_id' => $listing->resolved('cancellation_policy_id'),
            'rate_plan_id' => $listing->rate_plan_id,

            /*
             * Omitted rather than guessed when the photographs were not loaded.
             *
             * The guard used to accept either relation, and `effectivePhotos()`
             * reads the listing's own photos first in every case — so a response
             * that had loaded only the property lazy-loaded the rest, which is an
             * N+1 on a list endpoint and, with lazy loading prevented, a 500.
             * It went unseen because Eloquent allows the lazy load when the query
             * returned a single model: one listing worked, two did not, and the
             * endpoint in question is the picker every booking is made through.
             *
             * A count of zero would be the wrong answer to "how many photos does
             * this have" when the truth is "this response did not ask".
             */
            'photo_count' => $this->when(
                $listing->relationLoaded('photos')
                    && ($listing->photos->isNotEmpty() || $listing->property?->relationLoaded('photos') === true),
                fn (): int => count($listing->effectivePhotos()),
            ),

            'property' => new PropertyResource($this->whenLoaded('property')),
            'photos' => $this->when(
                $listing->relationLoaded('photos'),
                fn () => PropertyPhotoResource::collection($listing->effectivePhotos()),
            ),
            'channel_listings' => ChannelListingResource::collection($this->whenLoaded('channelListings')),

            'published_at' => $listing->published_at?->toIso8601String(),
            'created_at' => $listing->created_at?->toIso8601String(),
            'updated_at' => $listing->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvedContent(Listing $listing): array
    {
        // Resolution reads through to the property, so it is only meaningful
        // when that relation is available.
        if (! $listing->relationLoaded('property') && $listing->property === null) {
            return [];
        }

        $resolved = $listing->resolvedAttributes();

        foreach (['check_in_time', 'check_out_time'] as $timeField) {
            if (($resolved[$timeField] ?? null) instanceof \DateTimeInterface) {
                $resolved[$timeField] = $resolved[$timeField]->format('H:i');
            } elseif (is_string($resolved[$timeField] ?? null)) {
                $resolved[$timeField] = substr($resolved[$timeField], 0, 5);
            }
        }

        return $resolved;
    }
}
