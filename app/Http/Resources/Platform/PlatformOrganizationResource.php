<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use App\Domain\Organization\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tenant as the platform operator sees it.
 *
 * Separate from the tenant's own `OrganizationResource` on purpose. This one
 * carries things a tenant must never see about itself — the platform's private
 * notes, why it was suspended in the operator's words, which caps were
 * overridden for it — and omits nothing on the grounds of privacy, because the
 * audience is the person who runs the platform.
 *
 * It still holds no tenant *content*. A platform operator can see that a
 * company has four hundred reservations; reading one of them requires a support
 * session, which the customer gets a record of.
 *
 * @mixin Organization
 */
class PlatformOrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'slug' => $this->slug,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_operational' => $this->isOperational(),

            'base_currency' => $this->base_currency,
            'timezone' => $this->timezone,
            'country_code' => $this->country_code,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,

            'plan' => new PlanResource($this->whenLoaded('plan')),
            'plan_id' => $this->plan_id,

            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'trial_has_expired' => $this->trialHasExpired(),

            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspension_reason' => $this->suspension_reason,

            // The effective caps and features, with overrides applied, so the
            // console shows what is actually in force rather than what the plan
            // says in the abstract.
            'effective_limits' => $this->effectiveLimits(),
            'limit_overrides' => $this->limit_overrides,
            'feature_overrides' => $this->feature_overrides,

            // Never leaves the platform console.
            'platform_notes' => $this->platform_notes,

            'users_count' => $this->whenCounted('memberships'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
