<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Support\PlanFeature;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use App\Support\Models\BaseModel;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

        // Set by the platform console, never by a tenant's own API. They are
        // fillable so the console's service can assign them; the tenant-facing
        // controllers do not accept them.
        'plan_id',
        'suspended_at',
        'suspension_reason',
        'limit_overrides',
        'feature_overrides',
        'platform_notes',
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

    /**
     * The plan this organization is on, if any.
     *
     * Nullable, because a platform has customers before it has plans and an
     * organization on no plan must keep working rather than lose every feature.
     * {@see allows()} is what decides what "no plan" means.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Whether a feature is available to this organization.
     *
     * Three sources, in order: an explicit per-organization override, then the
     * plan, then the default. The override exists because a negotiated
     * exception should not require inventing a plan nobody else is on, and
     * because taking a feature away from one customer during a dispute must not
     * take it away from everybody on their plan.
     *
     * An organization on no plan is allowed everything. That is the deliberate
     * choice: this platform's plans are a commercial construct layered over a
     * working product, so the absence of one means "not yet metered", never
     * "crippled". A platform operator who wants the opposite sets a plan.
     */
    public function allows(string $feature): bool
    {
        $overrides = $this->feature_overrides ?? [];

        if (array_key_exists($feature, $overrides)) {
            return (bool) $overrides[$feature];
        }

        if ($this->plan === null) {
            return true;
        }

        return $this->plan->includes($feature);
    }

    /**
     * The numeric caps in force, with per-organization overrides applied.
     *
     * Null means unlimited. An organization on no plan has no caps.
     *
     * @return array<string, int|null>
     */
    public function effectiveLimits(): array
    {
        $limits = $this->plan?->limits() ?? array_fill_keys(PlanFeature::limitKeys(), null);

        foreach (($this->limit_overrides ?? []) as $key => $value) {
            if (! in_array($key, PlanFeature::limitKeys(), true)) {
                continue;
            }

            // An override of null is meaningful — it lifts a cap the plan sets.
            $limits[$key] = $value === null ? null : (int) $value;
        }

        return $limits;
    }

    public function limit(string $key): ?int
    {
        return $this->effectiveLimits()[$key] ?? null;
    }

    public function isSuspended(): bool
    {
        return $this->status === OrganizationStatus::Suspended;
    }

    public function isOnTrial(): bool
    {
        return $this->status === OrganizationStatus::Trial;
    }

    public function trialHasExpired(): bool
    {
        return $this->isOnTrial()
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isPast();
    }
}
