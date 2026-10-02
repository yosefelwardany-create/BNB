<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Properties\Models\PropertyHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Somebody to call about a property.
 *
 * The name and the number are *resolved* rather than echoed: where the row
 * points at a vendor or a member of staff, that record is the truth, so a number
 * corrected on the vendor is corrected here without anybody editing the helper.
 * The raw columns go out too, separately, because the form that edits a
 * freestanding contact needs the value actually stored rather than the resolved
 * one.
 *
 * @mixin PropertyHelper
 */
class PropertyHelperResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PropertyHelper $helper */
        $helper = $this->resource;

        return [
            'id' => $helper->getKey(),
            'property_id' => $helper->property_id,
            'role' => $helper->role,
            'role_label' => $helper->roleLabel(),
            'label' => $helper->label,

            'name' => $helper->displayName(),
            'phone' => $helper->contactNumber(),
            'email' => $helper->contactEmail(),

            // Where the contact details come from, so a screen can say "edit
            // this on the vendor" rather than offering a box that will be
            // ignored.
            'vendor_id' => $helper->vendor_id,
            'user_id' => $helper->user_id,
            'is_linked' => $helper->vendor_id !== null || $helper->user_id !== null,

            'own_name' => $helper->name,
            'own_phone' => $helper->phone,
            'own_email' => $helper->email,

            'notes' => $helper->notes,
            'is_primary' => (bool) $helper->is_primary,
            'position' => (int) $helper->position,
        ];
    }
}
