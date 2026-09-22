<?php

declare(strict_types=1);

namespace App\Domain\Properties\Models;

use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A grouping of properties, usually along the lines of a management contract,
 * an owner or a city. Used for reporting boundaries and for assigning
 * responsibility to a manager.
 */
class Portfolio extends BaseModel
{
    use Auditable, BelongsToOrganization, HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'description',
        'color',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        static::saving(function (Portfolio $portfolio): void {
            if (blank($portfolio->slug)) {
                $portfolio->slug = Str::slug($portfolio->name);
            }
        });
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function complexes(): HasMany
    {
        return $this->hasMany(Complex::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
