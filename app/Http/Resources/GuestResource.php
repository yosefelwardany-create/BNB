<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Guests\Models\Guest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Guest $resource
 */
class GuestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $guest = $this->resource;

        return [
            'id' => $guest->getKey(),
            'first_name' => $guest->first_name,
            'last_name' => $guest->last_name,
            'display_name' => $guest->display_name,
            'email' => $guest->email,
            'phone' => $guest->phone,
            'country_code' => $guest->country_code,
            'language' => $guest->language,
            'timezone' => $guest->timezone,
            'company' => $guest->company,

            'verification_status' => $guest->verification_status,
            // Identity documents are never returned in full.
            'document_type' => $guest->document_type,
            'document_number_masked' => $guest->maskedDocumentNumber(),

            'marketing_consent' => $guest->marketing_consent,
            'marketing_consent_at' => $guest->marketing_consent_at?->toIso8601String(),

            'stats' => [
                'reservations' => (int) $guest->reservations_count,
                'nights' => (int) $guest->nights_count,
                'lifetime_value' => $guest->lifetimeValue()->jsonSerialize(),
                'first_stay_date' => $guest->first_stay_date?->toDateString(),
                'last_stay_date' => $guest->last_stay_date?->toDateString(),
                'is_returning' => $guest->isReturning(),
            ],

            'notes' => $this->when(
                $request->user()?->can('guests.view') ?? false,
                $guest->notes,
            ),

            'merged_into_id' => $guest->merged_into_id,
            'tags' => $this->whenLoaded('tags', fn () => $guest->tags->pluck('name')->all()),
            'reservations' => ReservationResource::collection($this->whenLoaded('reservations')),

            'created_at' => $guest->created_at?->toIso8601String(),
        ];
    }
}
