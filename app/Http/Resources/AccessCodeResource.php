<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Locks\Models\AccessCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccessCode
 */
class AccessCodeResource extends JsonResource
{
    /**
     * Whether the code itself is included.
     *
     * Off by default, and turned on only by an endpoint whose caller has just
     * asked for it and holds the permission to operate the lock. A list of
     * working door codes is a set of keys; identifying which code is which
     * needs only the last four digits.
     */
    private bool $includeCode = false;

    public function withCode(): static
    {
        $this->includeCode = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'smart_lock_id' => $this->smart_lock_id,
            'reservation_id' => $this->reservation_id,
            'lock' => new SmartLockResource($this->whenLoaded('lock')),

            'purpose' => $this->purpose,
            'status' => $this->status,

            // Enough to say which code is which, and useless for opening
            // anything.
            'code_last4' => $this->code_last4,
            'code' => $this->when($this->includeCode, fn (): ?string => $this->code),

            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_until' => $this->valid_until?->toIso8601String(),
            'is_usable' => $this->isUsable(),

            // The distinction the whole module turns on. A usable code that is
            // simulated will not open a door, and a guest sent one is a guest
            // standing outside.
            'is_simulated' => (bool) $this->is_simulated,
            'opens_a_real_door' => $this->opensARealDoor(),

            'issued_at' => $this->issued_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_reason' => $this->revoked_reason,

            // Kept when a revocation was not confirmed by the lock: a code we
            // believe withdrawn that the door still accepts is exactly what
            // somebody needs to know about.
            'last_error' => $this->last_error,

            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'use_count' => (int) $this->use_count,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
