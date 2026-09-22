<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Properties\Models\CancellationPolicy;
use App\Domain\Properties\Models\Property;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A named commercial product: "Standard", "Non-refundable", "Weekly".
 *
 * A derived plan prices relative to its parent, so "non-refundable is 10%
 * below standard" stays true after the standard rate changes instead of
 * silently drifting.
 */
class RatePlan extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'name', 'slug', 'description', 'currency',
        'property_id', 'portfolio_id', 'parent_rate_plan_id',
        'derivation_type', 'derivation_value', 'cancellation_policy_id',
        'minimum_nights', 'maximum_nights', 'is_default', 'is_active', 'priority',
    ];

    protected function casts(): array
    {
        return [
            'derivation_value' => 'decimal:4',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected $attributes = [
        'is_default' => false,
        'is_active' => true,
        'priority' => 0,
    ];

    protected static function booted(): void
    {
        static::saving(function (RatePlan $plan): void {
            if (blank($plan->slug)) {
                $plan->slug = Str::slug($plan->name);
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_rate_plan_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_rate_plan_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function cancellationPolicy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class, 'cancellation_policy_id');
    }

    public function pricingRules(): HasMany
    {
        return $this->hasMany(PricingRule::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Apply this plan's derivation to a parent rate.
     *
     * Returns the rate unchanged when the plan is not derived, so callers do
     * not need to branch.
     */
    public function derive(Money $parentRate): Money
    {
        if ($this->parent_rate_plan_id === null || $this->derivation_type === null) {
            return $parentRate;
        }

        return match ($this->derivation_type) {
            'percent' => $parentRate->add($parentRate->percentage((float) $this->derivation_value)),
            'fixed' => $parentRate->add(Money::of((int) $this->derivation_value, $parentRate->currency)),
            default => $parentRate,
        };
    }

    /**
     * A human sentence describing the derivation, for the pricing trace.
     */
    public function derivationDescription(): ?string
    {
        if ($this->parent_rate_plan_id === null) {
            return null;
        }

        $value = (float) $this->derivation_value;

        return match ($this->derivation_type) {
            'percent' => sprintf(
                '%s%s%% of the %s plan',
                $value >= 0 ? '+' : '',
                rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.'),
                $this->parent?->name ?? 'parent',
            ),
            'fixed' => sprintf(
                '%s%d minor units against the %s plan',
                $value >= 0 ? '+' : '',
                (int) $value,
                $this->parent?->name ?? 'parent',
            ),
            default => null,
        };
    }
}
