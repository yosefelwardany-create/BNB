<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Domain\Users\Models\Permission;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Users\Support\RoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private AccessControl $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = $this->app->make(AccessControl::class);
    }

    public function test_a_role_grants_its_permissions(): void
    {
        $organization = $this->createOrganization();
        $agent = $this->createUser($organization, [RoleRegistry::RESERVATIONS_AGENT]);

        $this->assertTrue($this->access->allows($agent, 'reservations.create', $organization));
        $this->assertTrue($this->access->allows($agent, 'guests.update', $organization));
    }

    public function test_a_role_withholds_permissions_it_does_not_include(): void
    {
        $organization = $this->createOrganization();
        $agent = $this->createUser($organization, [RoleRegistry::RESERVATIONS_AGENT]);

        // A reservations agent must not be able to reach the ledger or change
        // owner payouts.
        $this->assertFalse($this->access->allows($agent, 'ledger.post', $organization));
        $this->assertFalse($this->access->allows($agent, 'owner_payouts.manage', $organization));
        $this->assertFalse($this->access->allows($agent, 'roles.manage', $organization));
    }

    public function test_read_only_role_cannot_write_anything(): void
    {
        $organization = $this->createOrganization();
        $viewer = $this->createUser($organization, [RoleRegistry::READ_ONLY]);

        $permissions = $this->access->permissionsFor($viewer, $organization);

        foreach ($permissions as $permission) {
            $this->assertStringContainsString(
                '.view',
                $permission,
                sprintf('The read-only role unexpectedly grants [%s].', $permission),
            );
        }
    }

    public function test_several_roles_combine_their_permissions(): void
    {
        $organization = $this->createOrganization();
        $user = $this->createUser($organization, [
            RoleRegistry::RESERVATIONS_AGENT,
            RoleRegistry::ACCOUNTANT,
        ]);

        $this->assertTrue($this->access->allows($user, 'reservations.create', $organization));
        $this->assertTrue($this->access->allows($user, 'ledger.post', $organization));
    }

    public function test_a_direct_deny_override_beats_a_role_grant(): void
    {
        $organization = $this->createOrganization();
        $manager = $this->createUser($organization, [RoleRegistry::PROPERTY_MANAGER]);

        $this->assertTrue($this->access->allows($manager, 'reservations.cancel', $organization));

        $membership = $this->membershipOf($manager, $organization);
        $permission = Permission::query()->where('name', 'reservations.cancel')->firstOrFail();

        $membership->permissionOverrides()->attach($permission->getKey(), ['effect' => 'deny']);
        $membership->touch();
        $this->access->forget($membership);

        $this->assertFalse($this->access->allows($manager, 'reservations.cancel', $organization));
    }

    public function test_a_direct_allow_override_grants_a_permission_no_role_provides(): void
    {
        $organization = $this->createOrganization();
        $cleaner = $this->createUser($organization, [RoleRegistry::CLEANER]);

        $this->assertFalse($this->access->allows($cleaner, 'guests.view', $organization));

        $membership = $this->membershipOf($cleaner, $organization);
        $permission = Permission::query()->where('name', 'guests.view')->firstOrFail();

        $membership->permissionOverrides()->attach($permission->getKey(), ['effect' => 'allow']);
        $membership->touch();
        $this->access->forget($membership);

        $this->assertTrue($this->access->allows($cleaner, 'guests.view', $organization));
    }

    public function test_permissions_do_not_carry_across_organizations(): void
    {
        $first = $this->createOrganization();
        $admin = $this->createUser($first, [RoleRegistry::ORGANIZATION_ADMIN]);

        $second = $this->createOrganization();

        // The same person has no standing at all in a company they do not work
        // for, regardless of how senior they are elsewhere.
        $this->assertTrue($this->access->allows($admin, 'financials.view', $first));
        $this->assertFalse($this->access->allows($admin, 'financials.view', $second));
        $this->assertSame([], $this->access->permissionsFor($admin, $second));
    }

    public function test_a_suspended_membership_grants_nothing(): void
    {
        $organization = $this->createOrganization();
        $user = $this->createUser($organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        $membership = $this->membershipOf($user, $organization);
        $membership->status = \App\Domain\Users\Enums\MembershipStatus::Suspended;
        $membership->save();

        $this->access->flushMemo();

        $this->assertSame([], $this->access->permissionsFor($user, $organization));
        $this->assertFalse($this->access->allows($user, 'properties.view', $organization));
    }

    public function test_platform_administrators_bypass_the_permission_check(): void
    {
        $organization = $this->createOrganization();

        $admin = $this->createUser($organization, [RoleRegistry::READ_ONLY], [
            'is_platform_admin' => true,
        ]);

        $this->assertTrue($this->access->allows($admin, 'ledger.post', $organization));
    }

    public function test_property_restrictions_are_reported_for_restricted_members(): void
    {
        $organization = $this->createOrganization();
        $cleaner = $this->createUser($organization, [RoleRegistry::CLEANER]);

        $this->assertNull($this->access->restrictedPropertyIds($cleaner, $organization));

        $membership = $this->membershipOf($cleaner, $organization);
        $membership->restricted_to_properties = true;
        $membership->save();

        $this->assertSame([], $this->access->restrictedPropertyIds($cleaner, $organization));
    }

    public function test_the_gate_resolves_registry_permissions(): void
    {
        $organization = $this->createOrganization();
        $agent = $this->createUser($organization, [RoleRegistry::RESERVATIONS_AGENT]);

        $this->actingAs($agent);
        $this->actingForOrganization($organization);

        $this->assertTrue($agent->can('reservations.create'));
        $this->assertFalse($agent->can('ledger.post'));
    }
}
