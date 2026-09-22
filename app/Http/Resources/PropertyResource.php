<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Properties\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @property Property $resource
 */
class PropertyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $property = $this->resource;

        return [
            'id' => $property->getKey(),
            'name' => $property->name,
            'internal_name' => $property->internal_name,
            'display_name' => $property->displayName(),
            'slug' => $property->slug,
            'reference' => $property->reference,

            'property_type' => $property->property_type->value,
            'property_type_label' => $property->property_type->label(),
            'rental_kind' => $property->rental_kind->value,
            'status' => $property->status->value,
            'is_multi_unit' => $property->is_multi_unit,
            'tracks_availability_per_unit' => $property->tracksAvailabilityPerUnit(),

            'portfolio_id' => $property->portfolio_id,
            'complex_id' => $property->complex_id,

            'address' => [
                'line_1' => $property->address_line_1,
                'line_2' => $property->address_line_2,
                'city' => $property->city,
                'state' => $property->state,
                'postal_code' => $property->postal_code,
                'country_code' => $property->country_code,
                'neighbourhood' => $property->neighbourhood,
                'latitude' => $property->latitude === null ? null : (float) $property->latitude,
                'longitude' => $property->longitude === null ? null : (float) $property->longitude,
            ],

            'timezone' => $property->timezone,
            'currency' => $property->currency,
            'local_time' => $property->localNow()->toIso8601String(),

            'capacity' => [
                'bedrooms' => (int) $property->bedrooms,
                'bathrooms' => (float) $property->bathrooms,
                'beds' => (int) $property->beds,
                'max_occupancy' => (int) $property->max_occupancy,
                'max_adults' => $property->max_adults,
                'max_children' => $property->max_children,
                'max_infants' => $property->max_infants,
                'max_pets' => (int) $property->max_pets,
                'size_value' => $property->size_value,
                'size_unit' => $property->size_unit,
            ],

            'content' => [
                'summary' => $property->summary,
                'description' => $property->description,
                'space_description' => $property->space_description,
                'neighbourhood_description' => $property->neighbourhood_description,
                'transit_description' => $property->transit_description,
                'house_rules' => $property->house_rules,
                'check_in_instructions' => $property->check_in_instructions,
                'check_out_instructions' => $property->check_out_instructions,
            ],

            'arrival' => [
                'check_in_time' => $this->timeString($property->check_in_time),
                'check_out_time' => $this->timeString($property->check_out_time),
                'check_in_until' => $this->timeString($property->check_in_until),
                'check_in_method' => $property->check_in_method,
            ],

            // Access credentials are only ever included for people whose role
            // requires them, and never in list responses.
            'access' => $this->when(
                $request->user() !== null
                    && Gate::forUser($request->user())->allows('viewAccessDetails', $property),
                fn (): array => [
                    'wifi_network' => $property->wifi_network,
                    'wifi_password' => $property->wifi_password,
                    'door_code' => $property->door_code,
                    'access_notes' => $property->access_notes,
                ],
            ),

            'pricing' => [
                'base_rate' => $property->baseRate()->jsonSerialize(),
                'cleaning_fee' => $property->cleaningFee()->jsonSerialize(),
                'security_deposit' => $property->securityDeposit()->jsonSerialize(),
                'extra_guest_fee' => $property->extraGuestFee()->jsonSerialize(),
                'extra_guest_after' => $property->extra_guest_after,
                'minimum_nights' => (int) $property->minimum_nights,
                'maximum_nights' => $property->maximum_nights,
                'instant_book' => $property->instant_book,
            ],

            'operations' => [
                'cleaning_duration_minutes' => (int) $property->cleaning_duration_minutes,
                'preparation_hours' => (int) $property->preparation_hours,
            ],

            'cancellation_policy_id' => $property->cancellation_policy_id,

            'internal_notes' => $this->when(
                $request->user()?->can('properties.update') ?? false,
                $property->internal_notes,
            ),

            // Only present when the caller asked for the counts; reading an
            // attribute that was never selected is an error, not a null.
            'counts' => $this->whenCounted('units', fn (): array => [
                'units' => (int) $property->units_count,
                'listings' => (int) ($property->getAttributes()['listings_count'] ?? 0),
            ]),

            'amenities' => AmenityResource::collection($this->whenLoaded('amenities')),
            'photos' => PropertyPhotoResource::collection($this->whenLoaded('photos')),
            'rooms' => PropertyRoomResource::collection($this->whenLoaded('rooms')),
            'units' => UnitResource::collection($this->whenLoaded('units')),
            'unit_types' => UnitTypeResource::collection($this->whenLoaded('unitTypes')),
            'listings' => ListingResource::collection($this->whenLoaded('listings')),
            'portfolio' => new PortfolioResource($this->whenLoaded('portfolio')),
            'tags' => $this->whenLoaded('tags', fn () => $property->tags->pluck('name')->all()),

            'created_at' => $property->created_at?->toIso8601String(),
            'updated_at' => $property->updated_at?->toIso8601String(),
            'activated_at' => $property->activated_at?->toIso8601String(),
        ];
    }

    private function timeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('H:i')
            : substr((string) $value, 0, 5);
    }
}
