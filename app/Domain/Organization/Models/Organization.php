<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use App\Support\Models\BaseModel;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tenant. Every other record in the platform belongs to exactly one
 * organization, which represents a property-management company.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property OrganizationStatus $status
 * @property string $base_currency
 * @property string $timezone
 */
class Organization extends BaseModel
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'name',
        'legal_name',
        'slug',
        'status',
        'base_currency',
        'timezone',
        'locale',
        'country_code',
        'contact_email',
        'contact_phone',
        'website',
        'tax_identifier',
        'branding',
        'settings',

        // Set by the platform owner's account administration, never by a
        // client's own API. Fillable so the administration service can assign
        // them; the tenant-facing controllers do not accept them.
        'suspended_at',
        'suspension_reason',
        'platform_notes',

        // Retired with the subscription model. The columns remain so existing
        // rows stay readable; nothing reads or enforces them any more.
        'trial_ends_at',
        'plan_id',
        'limit_overrides',
        'feature_overrides',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'branding' => 'array',
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'limit_overrides' => 'array',
            'feature_overrides' => 'array',
        ];
    }

    protected $attributes = [
        'status' => 'active',
        'base_currency' => 'CAD',
        'timezone' => 'UTC',
        'locale' => 'en',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->withPivot(['id', 'status', 'job_title'])
            ->withTimestamps();
    }

    /**
     * Roles available inside this organization: the system roles shipped with
     * the platform plus any the organization has defined itself.
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * Read a namespaced setting, e.g. `reservations.default_check_in_time`.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public function putSetting(string $key, mixed $value): void
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->settings = $settings;
    }

    public function isOperational(): bool
    {
        return $this->status->allowsAccess();
    }

    public function isSuspended(): bool
    {
        return $this->status === OrganizationStatus::Suspended;
    }
}
