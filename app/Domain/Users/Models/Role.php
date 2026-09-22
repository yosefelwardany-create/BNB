<?php

declare(strict_types=1);

namespace App\Domain\Users\Models;

use App\Domain\Organization\Models\Organization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named bundle of permissions.
 *
 * A role with a null `organization_id` is a system role shipped with the
 * platform and shared by every tenant; it cannot be edited. Organizations
 * create their own roles (optionally by cloning a system role) and those carry
 * their `organization_id`.
 *
 * Roles are not tenant-scoped through the usual global scope because system
 * roles must remain visible to everyone; scoping is expressed explicitly by
 * {@see scopeAvailableTo}.
 *
 * @property string $slug
 * @property ?string $organization_id
 * @property bool $is_system
 */
class Role extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'slug',
        'name',
        'description',
        'portal',
        'is_system',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    protected $attributes = [
        'is_system' => false,
        'is_default' => false,
        'portal' => 'admin',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role')->withTimestamps();
    }

    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class, 'membership_role')->withTimestamps();
    }

    /**
     * System roles plus the roles belonging to the given organization.
     */
    public function scopeAvailableTo(Builder $query, Organization|string|null $organization): Builder
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $query->where(function (Builder $q) use ($id): void {
            $q->whereNull('organization_id');

            if ($id !== null) {
                $q->orWhere('organization_id', $id);
            }
        });
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->whereNull('organization_id');
    }

    public function isSystem(): bool
    {
        return $this->organization_id === null;
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return $this->permissions->pluck('name')->all();
    }
}
