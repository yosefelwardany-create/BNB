<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Pricing\Models\RatePlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RatePlan
 */
class RatePlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'currency' => $this->currency,

            'property_id' => $this->property_id,
            'portfolio_id' => $this->portfolio_id,

            // A derived plan prices relative to its parent — "non-refundable
            // is the standard rate less 10%" — so a change to the parent flows
            // through instead of being copied and drifting.
            'parent_rate_plan_id' => $this->parent_rate_plan_id,
            'derivation_type' => $this->derivation_type,
            'derivation_value' => $this->derivation_value === null ? null : (float) $this->derivation_value,
            'is_derived' => $this->parent_rate_plan_id !== null,

            'cancellation_policy_id' => $this->cancellation_policy_id,
            'minimum_nights' => $this->minimum_nights,
            'maximum_nights' => $this->maximum_nights,

            'is_default' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,
            'priority' => (int) $this->priority,

            'parent' => new RatePlanResource($this->whenLoaded('parent')),
            'children' => RatePlanResource::collection($this->whenLoaded('children')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
