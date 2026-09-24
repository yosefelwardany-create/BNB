<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use App\Domain\Platform\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,

            'price' => $this->price()->jsonSerialize(),
            'billing_interval' => $this->billing_interval,
            'trial_days' => (int) $this->trial_days,

            // Null means unlimited, and is reported as null rather than as a
            // large number: a client that renders "unlimited" needs to be able
            // to tell the difference.
            'limits' => $this->limits(),

            'features' => $this->features ?? [],

            'is_public' => (bool) $this->is_public,
            'is_active' => (bool) $this->is_active,
            'position' => (int) $this->position,

            'organizations_count' => $this->whenCounted('organizations'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
