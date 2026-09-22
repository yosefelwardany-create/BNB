<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Owners\Models\ManagementAgreement;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ManagementAgreement
 */
class ManagementAgreementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'owner_id' => $this->owner_id,

            // Null means the agreement covers every property this owner has;
            // a value scopes it to one, which is how a portfolio with a single
            // differently-negotiated property is modelled.
            'property_id' => $this->property_id,
            'property' => new PropertyResource($this->whenLoaded('property')),

            'name' => $this->name,
            'reference' => $this->reference,

            'commission_model' => $this->commission_model,
            'commission_rate' => $this->commission_rate === null ? null : (float) $this->commission_rate,
            'commission_amount' => $this->commission_amount === null ? null : (int) $this->commission_amount,
            'commission_tiers' => $this->commission_tiers,
            'currency' => $this->currency,

            // The settings that get disputed. Each is explicit rather than
            // implied by the commission model, because "20% of what, exactly"
            // is the question every statement argument turns on.
            'commission_on_accommodation' => (bool) $this->commission_on_accommodation,
            'commission_on_fees' => (bool) $this->commission_on_fees,
            'commission_on_taxes' => (bool) $this->commission_on_taxes,
            'deduct_channel_commission_first' => (bool) $this->deduct_channel_commission_first,
            'deduct_payment_fees_first' => (bool) $this->deduct_payment_fees_first,

            'owner_pays_cleaning' => (bool) $this->owner_pays_cleaning,
            'owner_pays_maintenance' => (bool) $this->owner_pays_maintenance,
            'owner_pays_supplies' => (bool) $this->owner_pays_supplies,
            'maintenance_markup_percent' => $this->maintenance_markup_percent === null
                ? null
                : (float) $this->maintenance_markup_percent,
            'maintenance_approval_threshold' => $this->maintenance_approval_threshold === null
                ? null
                : (int) $this->maintenance_approval_threshold,

            'owner_stay_nights_included' => (int) $this->owner_stay_nights_included,
            'charge_cleaning_for_owner_stays' => (bool) $this->charge_cleaning_for_owner_stays,

            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'notice_period_days' => $this->notice_period_days,
            'status' => $this->status,
            'is_in_force' => $this->isInForceOn(CarbonImmutable::today()),

            'terms' => $this->terms,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
