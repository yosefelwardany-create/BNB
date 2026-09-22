<?php

declare(strict_types=1);

namespace App\Domain\Operations\Models;

use App\Domain\Users\Models\Membership;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A group of staff who take work together — a housekeeping crew, the
 * maintenance rota. Assigning to a team rather than a person lets the team
 * itself decide who picks the job up.
 */
class Team extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $fillable = [
        'organization_id', 'name', 'slug', 'kind', 'description', 'color', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected $attributes = ['kind' => 'cleaning', 'is_active' => true];

    protected static function booted(): void
    {
        static::saving(function (Team $team): void {
            if (blank($team->slug)) {
                $team->slug = Str::slug($team->name);
            }
        });
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class, 'team_members')
            ->withPivot('is_lead')
            ->withTimestamps();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
