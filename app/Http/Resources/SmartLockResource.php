<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Locks\Models\SmartLock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SmartLock
 */
class SmartLockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider,
            'location' => $this->location,

            'property_id' => $this->property_id,
            'unit_id' => $this->unit_id,
            'property' => new PropertyResource($this->whenLoaded('property')),

            'status' => $this->status,
            'is_reachable' => $this->isReachable(),
            'battery_percent' => $this->battery_percent,
            'has_low_battery' => $this->hasLowBattery(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),

            // Whether this connection reaches a real door. Travels with every
            // response, because a code issued against a simulated lock opens
            // nothing and the interface has to be able to say so.
            'is_simulated' => (bool) $this->is_simulated,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
