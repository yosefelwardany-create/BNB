<?php

declare(strict_types=1);

namespace App\Domain\Locks\Models;

use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\Unit;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A door.
 *
 * `is_simulated` travels with the record rather than being looked up from the
 * provider when somebody asks. The provider in use can change, and a code
 * issued last week against a simulated connection must keep saying so: a guest
 * sent a code that no real door knows about is a guest standing outside at
 * midnight, and the record has to be able to explain how that happened.
 *
 * A stay usually needs more than one code — a building entrance and a flat
 * door — which is why `location` exists and why locks are per unit rather than
 * per property.
 */
class SmartLock extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    public const ACTIVE = 'active';

    public const OFFLINE = 'offline';

    public const INACTIVE = 'inactive';

    protected $fillable = [
        'organization_id', 'property_id', 'unit_id', 'name', 'provider',
        'connection_id', 'external_lock_id', 'location', 'status',
        'is_simulated', 'settings', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'battery_percent' => 'integer',
            'is_simulated' => 'boolean',
            'settings' => 'array',
            'metadata' => 'array',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    protected $attributes = [
        'location' => 'entrance',
        'status' => self::ACTIVE,
        'is_simulated' => false,
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function accessCodes(): HasMany
    {
        return $this->hasMany(AccessCode::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    /**
     * Locks that will stop working soon.
     *
     * The operational queue that matters most: a flat battery is a guest who
     * cannot get in, and it is entirely preventable given a day's notice.
     */
    public function scopeLowBattery(Builder $query, int $below = 20): Builder
    {
        return $query->whereNotNull('battery_percent')->where('battery_percent', '<', $below);
    }

    public function isReachable(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function hasLowBattery(int $below = 20): bool
    {
        return $this->battery_percent !== null && (int) $this->battery_percent < $below;
    }
}
