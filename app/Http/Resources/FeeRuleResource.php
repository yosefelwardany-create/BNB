<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Pricing\Models\FeeRule;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FeeRule
 */
class FeeRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = $this->currency ?: config('pms.default_currency', 'USD');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'kind' => $this->kind,

            // How the amount multiplies out: per stay, per night, per guest
            // per night, and so on. The single field that decides whether a
            // cleaning fee is 80 or 560.
            'charge_basis' => $this->charge_basis,
            'amount' => $this->amount === null
                ? null
                : Money::of((int) $this->amount, $currency)->jsonSerialize(),
            'percentage' => $this->percentage === null ? null : (float) $this->percentage,
            'currency' => $this->currency,

            'applies_after_guests' => $this->applies_after_guests,
            'applies_after_nights' => $this->applies_after_nights,
            'maximum_units' => $this->maximum_units,

            'property_id' => $this->property_id,
            'listing_id' => $this->listing_id,
            'portfolio_id' => $this->portfolio_id,
            'channels' => $this->channels,

            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),

            // Whether tax is charged on it, whether it comes back on
            // cancellation, and whether the guest may decline it. Three
            // independent questions that a single "mandatory" flag would
            // conflate.
            'is_taxable' => (bool) $this->is_taxable,
            'is_refundable' => (bool) $this->is_refundable,
            'is_optional' => (bool) $this->is_optional,

            // Whether it is folded into the headline nightly rate a channel
            // displays. Wrong, and the price a guest sees is not the price
            // they pay.
            'include_in_displayed_rate' => (bool) $this->include_in_displayed_rate,

            'is_active' => (bool) $this->is_active,
            'position' => (int) $this->position,
            'revenue_account_key' => $this->revenue_account_key,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
