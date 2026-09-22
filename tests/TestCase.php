<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\OrganizationProvisioner;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\Permission;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Users\Support\RoleRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * The permission catalogue and system roles are static reference data.
     * Seeding them once per process rather than per test saves a great deal of
     * time across the suite.
     */
    protected static bool $referenceDataSeeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();
    }

    protected function tearDown(): void
    {
        // Tenancy is a singleton for the lifetime of a request; tests must not
        // leak a bound tenant into the next one.
        if ($this->app !== null) {
            $this->app->make(TenantContext::class)->clear();
            $this->app->make(AccessControl::class)->flushMemo();
        }

        parent::tearDown();
    }

    protected function seedReferenceData(): void
    {
        if (Permission::query()->exists() && Role::query()->whereNull('organization_id')->exists()) {
            return;
        }

        Artisan::call('permissions:sync');
    }

    /**
     * Create an organization ready to be used, and bind it as the active
     * tenant.
     */
    protected function createOrganization(array $attributes = []): Organization
    {
        $provisioner = $this->app->make(OrganizationProvisioner::class);

        $organization = $provisioner->createOrganization(array_merge([
            'name' => 'Test Hospitality '.Str::random(6),
            'base_currency' => 'USD',
            'timezone' => 'UTC',
            'status' => 'active',
        ], $attributes));

        $this->app->make(\App\Domain\Accounting\Services\ChartOfAccountsInstaller::class)
            ->install($organization);
        $this->app->make(\App\Domain\Properties\Services\CancellationPolicyInstaller::class)
            ->install($organization);

        $this->actingForOrganization($organization);

        return $organization;
    }

    /**
     * Bind an organization as the active tenant without authenticating anyone.
     */
    protected function actingForOrganization(Organization $organization): Organization
    {
        $this->app->make(TenantContext::class)->set($organization);

        return $organization;
    }

    /**
     * Create a user with a membership in the organization carrying the given
     * system roles.
     *
     * @param  list<string>  $roles
     */
    protected function createUser(
        Organization $organization,
        array $roles = [RoleRegistry::ORGANIZATION_ADMIN],
        array $attributes = [],
    ): User {
        // `email_verified_at` and `is_platform_admin` are deliberately not
        // mass-assignable on the model — no request may ever set them — so the
        // helper writes them explicitly.
        $protected = array_intersect_key($attributes, array_flip([
            'email_verified_at', 'is_platform_admin',
        ]));

        $user = User::query()->create(array_merge([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'user-'.Str::lower(Str::random(10)).'@example.test',
            'password' => 'password-for-tests-1234',
            'status' => 'active',
        ], array_diff_key($attributes, $protected)));

        $user->forceFill($protected + ['email_verified_at' => now()])->save();

        $this->app->make(OrganizationProvisioner::class)
            ->attachUser($organization, $user, $roles);

        $this->app->make(AccessControl::class)->flushMemo();

        return $user->fresh();
    }

    /**
     * Authenticate as a user acting inside an organization, exactly as the API
     * middleware would arrange it.
     */
    protected function actingAsUser(User $user, ?Organization $organization = null): static
    {
        $this->actingAs($user, 'sanctum');

        if ($organization !== null) {
            $this->actingForOrganization($organization);
            $this->withHeader('X-Organization', $organization->getKey());
        }

        return $this;
    }

    /**
     * Shortcut: a fresh organization plus an admin user, both active.
     *
     * @return array{organization: Organization, user: User}
     */
    protected function createTenantWithAdmin(array $organizationAttributes = []): array
    {
        $organization = $this->createOrganization($organizationAttributes);
        $user = $this->createUser($organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        return ['organization' => $organization, 'user' => $user];
    }

    protected function membershipOf(User $user, Organization $organization): Membership
    {
        return Membership::query()
            ->withoutGlobalScope('organization')
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organization->getKey())
            ->firstOrFail();
    }

    /**
     * Run a callback with tenant scoping suspended, as platform tooling does.
     */
    protected function withoutTenantScope(callable $callback): mixed
    {
        return $this->app->make(TenantContext::class)->withoutScope($callback);
    }
}
