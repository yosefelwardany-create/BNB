<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Operations\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Vendor
 */
class VendorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'contact_name' => $this->contact_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'city' => $this->city,
            'country_code' => $this->country_code,
            'tax_identifier' => $this->tax_identifier,

            'hourly_rate' => $this->hourlyRate()?->jsonSerialize(),
            'callout_fee' => $this->callout_fee === null ? null : (int) $this->callout_fee,
            'currency' => $this->currency,

            'insurance_expires_on' => $this->insurance_expires_on?->toDateString(),
            'certifications' => $this->certifications ?? [],
            'rating' => $this->rating === null ? null : (float) $this->rating,

            'is_active' => (bool) $this->is_active,

            // Dispatching a contractor whose cover has lapsed is a real
            // liability, so the interface is told plainly rather than being
            // left to compare a date itself.
            'insurance_has_lapsed' => $this->insuranceHasLapsed(),
            'is_dispatchable' => $this->isDispatchable(),

            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
