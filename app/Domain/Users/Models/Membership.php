<?php

declare(strict_types=1);

namespace App\Domain\Users\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Enums\MembershipStatus;
use App\Support\Concerns\BelongsToOrganization;
use App\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A user's seat inside one organization.
 *
 * The membership — not the user — carries roles, direct permission overrides
 * and optional property restrictions, because the same person may be an
 * administrator at one company and a cleaner at another.
 *
 * It is a first-class entity rather than a pivot: it has its own identity,
 * its own relationships and its own lifecycle (invited, active, suspended).
 *
 * @property string $id
 * @property MembershipStatus $status
 */
class Membership extends BaseModel
{
    use BelongsToOrganization, HasFactory;

    protected $table = 'memberships';

    protected $fillable = [
        'organization_id',
        'user_id',
        'status',
        'job_title',
        'employment_type',
        'default_portal',
        'restricted_to_properties',
        'invited_by_id',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'restricted_to_properties' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => 'active',
        'restricted_to_properties' => false,
        'default_portal' => 'admin',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'membership_role')
            ->withTimestamps();
    }

    /**
     * Direct permission grants and denials that override the roles.
     */
    public function permissionOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'membership_permission')
            ->withPivot('effect')
            ->withTimestamps();
    }

    /**
     * When `restricted_to_properties` is true the member may only see the
     * properties listed here. Used for on-site staff and single-building
     * managers.
     */
    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'membership_property')
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status->grantsAccess();
    }

    public function organizationRelation(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
