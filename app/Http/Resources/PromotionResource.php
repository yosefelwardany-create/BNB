<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Pricing\Models\Promotion;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Promotion
 */
class PromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = $this->currency ?: config('pms.default_currency', 'USD');

        return [
            'id' => $this->id,
            'name' => $this->name,
            // Null for an automatic promotion: it applies without anybody
            // typing anything, which is a different product decision from a
            // code handed out in a campaign.
            'code' => $this->code,
            'is_automatic' => $this->code === null,
            'description' => $this->description,

            'discount_type' => $this->discount_type,
            'discount_value' => (float) $this->discount_value,
            'currency' => $this->currency,

            // When it can be *booked*, as against which *stays* it covers.
            'bookable_from' => $this->bookable_from?->toDateString(),
            'bookable_to' => $this->bookable_to?->toDateString(),
            'stay_from' => $this->stay_from?->toDateString(),
            'stay_to' => $this->stay_to?->toDateString(),

            'minimum_nights' => $this->minimum_nights,
            'minimum_spend' => $this->minimum_spend === null
                ? null
                : Money::of((int) $this->minimum_spend, $currency)->jsonSerialize(),

            'maximum_uses' => $this->maximum_uses,
            'maximum_uses_per_guest' => $this->maximum_uses_per_guest,
            'times_used' => (int) $this->times_used,

            // Whether there is any left. Derived rather than stored, because a
            // stored flag would be as current as the last job that swept it.
            'is_exhausted' => $this->maximum_uses !== null
                && (int) $this->times_used >= (int) $this->maximum_uses,

            'property_ids' => $this->property_ids,
            'channels' => $this->channels,
            'combinable' => (bool) $this->combinable,
            'applies_to_fees' => (bool) $this->applies_to_fees,
            'is_active' => (bool) $this->is_active,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
