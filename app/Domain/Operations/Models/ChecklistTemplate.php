<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Properties\Models\Property;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable checklist.
 *
 * Templates can be property-specific — a villa with a pool needs items a city
 * flat does not — with a general template as the fallback.
 */
class ChecklistTemplate extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'name', 'kind', 'description', 'property_id',
        'items', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected $attributes = ['is_default' => false, 'is_active' => true];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The template to use for a kind of work at a property: the most specific
     * one available.
     */
    public static function resolveFor(string $organizationId, string $kind, ?string $propertyId): ?self
    {
        return self::query()
            ->where('organization_id', $organizationId)
            ->where('kind', $kind)
            ->active()
            ->where(fn (Builder $q) => $q->where('property_id', $propertyId)->orWhereNull('property_id'))
            // A property-specific template wins over the general one.
            ->orderByRaw('property_id IS NULL')
            ->orderByDesc('is_default')
            ->first();
    }
}
