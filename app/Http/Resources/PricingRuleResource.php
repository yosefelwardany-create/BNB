<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Pricing\Models\PricingRule;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PricingRule
 */
class PricingRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // From the bound tenant rather than the rule's own organization
        // relation: reading it off the model would lazy-load an organization
        // per row, and every row in this collection belongs to the same one.
        $currency = app(TenantContext::class)->organization()?->base_currency
            ?? config('pms.default_currency', 'USD');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'kind' => $this->kind,

            // Null at every level means "everything at that level". The scope
            // is what decides which listings a rule reaches, so it is reported
            // whole rather than summarised.
            'scope' => [
                'property_id' => $this->property_id,
                'listing_id' => $this->listing_id,
                'unit_type_id' => $this->unit_type_id,
                'portfolio_id' => $this->portfolio_id,
                'rate_plan_id' => $this->rate_plan_id,
                'channels' => $this->channels,
            ],

            // When the rule is in force, as against which stay dates it
            // prices. Two different questions that look alike, and confusing
            // them is how a summer rule starts pricing winter.
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'stay_from' => $this->stay_from?->toDateString(),
            'stay_to' => $this->stay_to?->toDateString(),
            'days_of_week' => $this->days_of_week,

            // Evaluated against a fixed, safe vocabulary. There is no
            // expression language here and nothing is ever eval'd.
            'conditions' => $this->conditions,

            'adjustment_type' => $this->adjustment_type,
            'adjustment_value' => (int) $this->adjustment_value,

            // Guard rails, so a mistyped percentage cannot sell a villa for
            // nothing or price it out of the market.
            'floor_rate' => $this->floor_rate === null
                ? null
                : Money::of((int) $this->floor_rate, $currency)->jsonSerialize(),
            'ceiling_rate' => $this->ceiling_rate === null
                ? null
                : Money::of((int) $this->ceiling_rate, $currency)->jsonSerialize(),

            'priority' => (int) $this->priority,
            'is_exclusive' => (bool) $this->is_exclusive,
            'is_active' => (bool) $this->is_active,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
