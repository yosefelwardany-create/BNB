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
        'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'branding' => 'array',
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => 'trial',
        'base_currency' => 'USD',
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
}
