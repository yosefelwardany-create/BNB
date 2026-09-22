<?php

declare(strict_types=1);

namespace App\Domain\Users\Models;

use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person who signs in.
 *
 * Users are deliberately *not* tenant-scoped: the same person can work for
 * more than one property-management company (a common arrangement for
 * accountants and contract cleaners). Access to a tenant is granted by a
 * {@see Membership}, which carries the roles and any property restrictions.
 *
 * @property string $id
 * @property string $email
 * @property UserStatus $status
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'timezone',
        'locale',
        'avatar_path',
        'status',
        'is_platform_admin',
        'notification_preferences',
        'mfa_enabled',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
        'mfa_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'is_platform_admin' => 'boolean',
            'mfa_enabled' => 'boolean',
            'mfa_confirmed_at' => 'datetime',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'notification_preferences' => 'array',
            'last_login_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => 'active',
        'timezone' => 'UTC',
        'locale' => 'en',
        'is_platform_admin' => false,
        'mfa_enabled' => false,
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'memberships')
            ->withPivot(['id', 'status', 'job_title'])
            ->withTimestamps();
    }

    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    /**
     * The membership for a given organization, if any.
     */
    public function membershipFor(Organization|string $organization): ?Membership
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        if ($this->relationLoaded('memberships')) {
            return $this->memberships->firstWhere('organization_id', $id);
        }

        return $this->memberships()->where('organization_id', $id)->first();
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function getNameAttribute(): string
    {
        return $this->fullName();
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Platform administrators operate above the tenancy boundary. This flag is
     * never settable through the organization-facing API.
     */
    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }
}
