<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An external contractor.
 *
 * Insurance and certification expiry are tracked because dispatching a
 * contractor whose cover has lapsed is a real liability, and the date is the
 * only thing that makes that checkable.
 */
class Vendor extends BaseModel
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'category', 'contact_name', 'email', 'phone',
        'address_line_1', 'city', 'country_code', 'tax_identifier',
        'hourly_rate', 'callout_fee', 'currency',
        'insurance_expires_on', 'certifications', 'rating', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'insurance_expires_on' => 'immutable_date',
            'certifications' => 'array',
            'rating' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected $attributes = ['is_active' => true];

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function hourlyRate(): ?Money
    {
        return $this->hourly_rate === null || $this->currency === null
            ? null
            : Money::of((int) $this->hourly_rate, $this->currency);
    }

    /**
     * Whether the vendor may currently be dispatched.
     */
    public function isDispatchable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return ! $this->insuranceHasLapsed();
    }

    public function insuranceHasLapsed(): bool
    {
        return $this->insurance_expires_on !== null
            && $this->insurance_expires_on->isPast();
    }
}
