<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Support\PlanFeature;
use App\Support\Concerns\Auditable;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What a tenant is sold.
 *
 * Platform-owned: there is deliberately no `organization_id` and no
 * `BelongsToOrganization`, because a plan is the platform operator's object and
 * a tenant may only ever read the one it is on.
 *
 * Soft-deleted rather than deleted. Organizations reference a plan, and a plan
 * that has been retired still has to be nameable on the invoice of every month
 * somebody was on it.
 */
class Plan extends BaseModel
{
    use Auditable, SoftDeletes;

    protected $table = 'plans';

    protected $fillable = [
        'name', 'slug', 'description',
        'price_amount', 'currency', 'billing_interval', 'trial_days',
        'max_properties', 'max_units', 'max_listings', 'max_users',
        'max_reservations_per_month',
        'features', 'is_public', 'is_active', 'position',
    ];

    protected function casts(): array
    {
        return [
            'price_amount' => 'integer',
            'trial_days' => 'integer',
            'max_properties' => 'integer',
            'max_units' => 'integer',
            'max_listings' => 'integer',
            'max_users' => 'integer',
            'max_reservations_per_month' => 'integer',
            'features' => 'array',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    protected $attributes = [
        'billing_interval' => 'monthly',
        'currency' => 'EUR',
        'is_public' => true,
        'is_active' => true,
    ];

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)->where('is_active', true);
    }

    public function price(): Money
    {
        return Money::of((int) $this->price_amount, $this->currency);
    }

    /**
     * Whether this plan includes a feature.
     *
     * Fails closed: an unrecognised key is not included, and an empty feature
     * list includes nothing. A plan whose features were never configured must
     * not accidentally be the most generous one on the platform.
     */
    public function includes(string $feature): bool
    {
        if (! PlanFeature::exists($feature)) {
            return false;
        }

        return in_array($feature, $this->features ?? [], true);
    }

    /**
     * The numeric caps, keyed as the limit registry names them.
     *
     * @return array<string, int|null>
     */
    public function limits(): array
    {
        $limits = [];

        foreach (PlanFeature::limitKeys() as $key) {
            $value = $this->{$key};

            // Null is unlimited. Nought is a real answer that happens to mean
            // "none allowed", so it must survive this untouched.
            $limits[$key] = $value === null ? null : (int) $value;
        }

        return $limits;
    }
}
