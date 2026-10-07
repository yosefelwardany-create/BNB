<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A property as its client sees it.
 *
 * A deliberately narrow shape, separate from {@see PropertyResource}. The
 * management resource carries the Hostex synchronisation state, the agent's
 * configuration and connection, access codes, internal notes and operational
 * settings — all of them the management company's business. This one carries
 * what the client is entitled to know about their own property: what it is,
 * where it is, what it sleeps, how it is presented and priced, and whether it
 * is on sale.
 *
 * @property Property $resource
 */
class ClientPropertyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $property = $this->resource;
        $hostex = is_array($property->settings['hostex'] ?? null) ? $property->settings['hostex'] : [];

        return [
            'id' => $property->getKey(),
            'name' => $property->name,
            'display_name' => $property->displayName(),
            'property_type' => $property->property_type->value,
            'property_type_label' => $property->property_type->label(),
            'status' => $property->status->value,

            'address' => [
                'line_1' => $property->address_line_1,
                'line_2' => $property->address_line_2,
                'city' => $property->city,
                'state' => $property->state,
                'postal_code' => $property->postal_code,
                'country_code' => $property->country_code,
            ],

            'timezone' => $property->timezone,
            'currency' => $property->currency,

            'capacity' => [
                'bedrooms' => (int) $property->bedrooms,
                'bathrooms' => (float) $property->bathrooms,
                'beds' => (int) $property->beds,
                'max_occupancy' => (int) $property->max_occupancy,
            ],

            'content' => [
                'summary' => $property->summary,
                'description' => $property->description,
                'house_rules' => $property->house_rules,
            ],

            'arrival' => [
                'check_in_time' => $this->timeString($property->check_in_time),
                'check_out_time' => $this->timeString($property->check_out_time),
            ],

            'pricing' => [
                'base_rate' => $property->baseRate()->jsonSerialize(),
                'cleaning_fee' => $property->cleaningFee()->jsonSerialize(),
                'minimum_nights' => (int) $property->minimum_nights,
            ],

            // Where the property is listed, and whether that listing is live —
            // nothing about how the connection is configured or synchronised.
            'listing' => [
                'channel_url' => is_string($hostex['url'] ?? null) ? $hostex['url'] : null,
                'channel_status' => is_string($hostex['shelf_status'] ?? null) ? $hostex['shelf_status'] : null,
                'published_listings' => $this->whenLoaded(
                    'listings',
                    fn (): int => $property->listings->filter(fn ($l): bool => $l->status->value === 'published')->count(),
                ),
            ],

            'photos' => $this->whenLoaded('photos', fn (): array => $property->photos
                ->map(fn (PropertyPhoto $photo): array => [
                    'id' => $photo->getKey(),
                    'url' => $photo->url(),
                    'caption' => $photo->caption,
                    'is_cover' => (bool) $photo->is_cover,
                ])->values()->all()),

            'amenities' => $this->whenLoaded('amenities', fn (): array => $property->amenities
                ->map(fn ($amenity): array => ['id' => $amenity->getKey(), 'name' => $amenity->name])
                ->values()->all()),
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
