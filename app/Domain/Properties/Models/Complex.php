<?php

declare(strict_types=1);

namespace App\Domain\Properties\Models;

use App\Support\Concerns\Auditable;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A building or development containing several properties.
 *
 * It exists so shared facts — the street address, the concierge arrangement,
 * the shared pool — are recorded once instead of copied into every flat, and
 * so operations can be planned per building rather than per flat.
 */
class Complex extends BaseModel
{
    use Auditable, BelongsToOrganization, HasFactory, SoftDeletes;

    protected $table = 'complexes';

    protected $fillable = [
        'organization_id',
        'portfolio_id',
        'name',
        'slug',
        'description',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country_code',
        'latitude',
        'longitude',
        'timezone',
        'floors',
        'shared_amenities',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'shared_amenities' => 'array',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Complex $complex): void {
            if (blank($complex->slug)) {
                $complex->slug = Str::slug($complex->name);
            }
        });
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
