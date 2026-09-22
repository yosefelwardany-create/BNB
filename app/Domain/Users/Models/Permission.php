<?php

declare(strict_types=1);

namespace App\Domain\Users\Models;

use App\Domain\Users\Support\PermissionRegistry;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A single granular capability, e.g. `reservations.cancel`.
 *
 * Permissions are global to the platform (not tenant-scoped): the catalogue is
 * defined by {@see PermissionRegistry} and kept in
 * sync by the `permissions:sync` command.
 *
 * @property string $name
 * @property string $module
 */
class Permission extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'module',
        'description',
    ];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'permission_role')->withTimestamps();
    }
}
