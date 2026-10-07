<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use App\Domain\Organization\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A client account as the platform owner sees it.
 *
 * Separate from the tenant's own `OrganizationResource` on purpose. This one
 * carries things a client must never see about itself — the platform's private
 * notes, why it was suspended in the owner's words — and omits nothing on the
 * grounds of privacy, because the audience is the person who runs the platform.
 *
 * It still holds no tenant *content*. Reading a client's properties and
 * bookings is done through the ordinary tenant API with an `X-Organization`
 * header, where every read and write is scoped to that one account.
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

            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspension_reason' => $this->suspension_reason,

            // Never leaves the owner's administration screens.
            'platform_notes' => $this->platform_notes,

            'users_count' => $this->whenCounted('memberships'),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
