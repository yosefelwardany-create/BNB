<?php

declare(strict_types=1);

namespace App\Domain\Organization\Services;

use App\Domain\Accounting\Services\ChartOfAccountsInstaller;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Services\CancellationPolicyInstaller;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a new tenant and everything it needs to be usable immediately.
 *
 * A freshly provisioned organization has: its own settings, an administrator
 * account, a chart of accounts and the standard cancellation policies — enough
 * to add a property and take a booking. Everything else (properties, listings,
 * rates) the customer creates themselves.
 */
class OrganizationProvisioner
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly ChartOfAccountsInstaller $chartOfAccounts,
        private readonly CancellationPolicyInstaller $cancellationPolicies,
    ) {}

    /**
     * Provision an organization together with its first administrator.
     *
     * @param  array{name: string, base_currency?: string, timezone?: string, locale?: string, country_code?: string, legal_name?: string}  $organizationAttributes
     * @param  array{first_name: string, last_name?: string, email: string, password: string, phone?: string}  $adminAttributes
     * @return array{organization: Organization, user: User, membership: Membership}
     */
    public function provision(array $organizationAttributes, array $adminAttributes): array
    {
        return DB::transaction(function () use ($organizationAttributes, $adminAttributes): array {
            $organization = $this->createOrganization($organizationAttributes);

            // Everything below must be created *inside* the new tenant.
            return $this->tenancy->runAs($organization, function () use ($organization, $adminAttributes): array {
                $user = $this->createOrFindUser($adminAttributes);

                $membership = $this->attachUser(
                    $organization,
                    $user,
                    [RoleRegistry::ORGANIZATION_ADMIN],
                    $adminAttributes['job_title'] ?? 'Administrator',
                );

                $this->chartOfAccounts->install($organization);
                $this->cancellationPolicies->install($organization);

                return compact('organization', 'user', 'membership');
            });
        });
    }

    /**
     * Create the organization record, deriving a unique slug from its name.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createOrganization(array $attributes): Organization
    {
        // The organization is the tenant itself, so it is created outside any
        // tenant scope.
        return $this->tenancy->withoutScope(function () use ($attributes): Organization {
            return Organization::query()->create([
                'name' => $attributes['name'],
                'legal_name' => $attributes['legal_name'] ?? null,
                'slug' => $this->uniqueSlug($attributes['slug'] ?? $attributes['name']),
                'status' => $attributes['status'] ?? 'trial',
                'base_currency' => strtoupper($attributes['base_currency'] ?? 'USD'),
                'timezone' => $attributes['timezone'] ?? 'UTC',
                'locale' => $attributes['locale'] ?? 'en',
                'country_code' => isset($attributes['country_code'])
                    ? strtoupper($attributes['country_code'])
                    : null,
                'contact_email' => $attributes['contact_email'] ?? null,
                'contact_phone' => $attributes['contact_phone'] ?? null,
                'trial_ends_at' => $attributes['trial_ends_at'] ?? now()->addDays(30),
                'settings' => $attributes['settings'] ?? [],
            ]);
        });
    }

    /**
     * Give a user a seat in the organization with the named system roles.
     *
     * @param  list<string>  $roleSlugs
     */
    public function attachUser(
        Organization $organization,
        User $user,
        array $roleSlugs,
        ?string $jobTitle = null,
        ?User $invitedBy = null,
    ): Membership {
        $membership = Membership::query()
            ->withoutGlobalScope('organization')
            ->firstOrCreate(
                [
                    'organization_id' => $organization->getKey(),
                    'user_id' => $user->getKey(),
                ],
                [
                    'status' => 'active',
                    'job_title' => $jobTitle,
                    'invited_by_id' => $invitedBy?->getKey(),
                    'joined_at' => now(),
                ],
            );

        $roles = Role::query()
            ->availableTo($organization)
            ->whereIn('slug', $roleSlugs)
            ->get();

        if ($roles->isNotEmpty()) {
            $membership->roles()->syncWithoutDetaching($roles->pluck('id')->all());

            $membership->default_portal = $roles->first()->portal ?? 'admin';
            $membership->save();
        }

        return $membership->fresh(['roles']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOrFindUser(array $attributes): User
    {
        $existing = User::query()->where('email', $attributes['email'])->first();

        if ($existing !== null) {
            return $existing;
        }

        return User::query()->create([
            'first_name' => $attributes['first_name'],
            'last_name' => $attributes['last_name'] ?? null,
            'email' => $attributes['email'],
            'password' => $attributes['password'],
            'phone' => $attributes['phone'] ?? null,
            'timezone' => $attributes['timezone'] ?? 'UTC',
            'locale' => $attributes['locale'] ?? 'en',
            'status' => 'active',
        ]);
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'organization';
        $slug = $base;
        $suffix = 1;

        while ($this->slugTaken($slug)) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    private function slugTaken(string $slug): bool
    {
        return Organization::withTrashed()->where('slug', $slug)->exists();
    }
}
