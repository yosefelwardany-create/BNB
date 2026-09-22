<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Pricing\Models\TaxRule;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaxRule
 */
class TaxRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = $this->currency ?: config('pms.default_currency', 'USD');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,

            'calculation' => $this->calculation,
            'rate' => $this->rate === null ? null : (float) $this->rate,
            'amount' => $this->amount === null
                ? null
                : Money::of((int) $this->amount, $currency)->jsonSerialize(),
            'currency' => $this->currency,

            'applies_to_accommodation' => (bool) $this->applies_to_accommodation,
            'applies_to_fees' => (bool) $this->applies_to_fees,
            'applies_to_fee_codes' => $this->applies_to_fee_codes,

            // A tax charged on top of other taxes. Rare, and wrong in either
            // direction by a compounding amount, so it is explicit.
            'compounds_on_taxes' => (bool) $this->compounds_on_taxes,

            // Long-stay exemptions are ordinary in city tourist taxes, and
            // getting them wrong overcharges exactly the guests who stay
            // longest.
            'maximum_nights' => $this->maximum_nights,
            'exempt_after_nights' => $this->exempt_after_nights,
            'exempt_guest_age_under' => $this->exempt_guest_age_under,
            'maximum_amount' => $this->maximum_amount === null
                ? null
                : Money::of((int) $this->maximum_amount, $currency)->jsonSerialize(),

            'property_id' => $this->property_id,
            'portfolio_id' => $this->portfolio_id,
            'country_code' => $this->country_code,
            'region' => $this->region,
            'city' => $this->city,

            // Channels that remit this tax themselves. On those bookings the
            // platform must not charge it again, and must not report it as
            // owed.
            'channels_collecting' => $this->channels_collecting,
            'channels' => $this->channels,

            'effective_from' => $this->effective_from?->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'priority' => (int) $this->priority,
            'is_active' => (bool) $this->is_active,
            'remittance_reference' => $this->remittance_reference,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
