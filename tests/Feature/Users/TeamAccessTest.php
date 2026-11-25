<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\Invitation;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\Permission;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Users\Services\InvitationService;
use App\Domain\Users\Support\RoleRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Who in an account sees what, set by the account itself.
 *
 * Roles, single permissions given or taken away, and a limit to some
 * properties were all enforced already, but only the platform could set
 * them, so an account had one login that saw everything. These are the
 * account's own controls over its team.
 */
class TeamAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private Property $north;

    private Property $south;

    protected function setUp(): void
    {
        parent::setUp();

        ['organization' => $this->organization, 'user' => $this->admin] = $this->createTenantWithAdmin();
        $this->north = Property::factory()->active()->create(['organization_id' => $this->organization->getKey(), 'name' => 'North Room']);
        $this->south = Property::factory()->active()->create(['organization_id' => $this->organization->getKey(), 'name' => 'South Room']);
    }

    public function test_an_admin_sees_everyone_with_what_they_can_do(): void
    {
        $agent = $this->createUser($this->organization, [RoleRegistry::RESERVATIONS_AGENT], ['first_name' => 'Rana']);

        $team = $this->actingAsUser($this->admin, $this->organization)->getJson('/api/v1/team')->assertOk();

        $members = collect($team->json('data.members'))->keyBy('user.email');
        $this->assertTrue($members[$this->admin->email]['is_you']);
        $this->assertSame(['organization-admin'], array_column($members[$this->admin->email]['roles'], 'slug'));
        $this->assertFalse($members[$agent->email]['is_you']);
        $this->assertSame(['reservations-agent'], array_column($members[$agent->email]['roles'], 'slug'));
        $this->assertFalse($members[$agent->email]['restricted_to_properties']);
        $this->assertContains('reservations.view', $members[$agent->email]['permissions']);

        // What can be chosen: the areas and their permissions, the ready-made
        // roles, and the account's properties.
        $options = $this->getJson('/api/v1/team/options')->assertOk();
        $this->assertContains('Reservations', array_column($options->json('data.areas'), 'name'));
        $roles = array_column($options->json('data.roles'), 'slug');
        $this->assertContains('reservations-agent', $roles);
        $this->assertNotContains('super-admin', $roles, 'Platform administration is not the account\'s to hand out.');
        $this->assertEqualsCanonicalizing(['North Room', 'South Room'], array_column($options->json('data.properties'), 'name'));
    }

    public function test_an_admin_narrows_what_a_member_sees(): void
    {
        $agent = $this->createUser($this->organization, [RoleRegistry::RESERVATIONS_AGENT]);

        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson('/api/v1/team/'.$this->seatOf($agent)->getKey(), [
                'role_ids' => [$this->role(RoleRegistry::RESERVATIONS_AGENT)->getKey()],
                'allow' => ['revenue.view'],
                'deny' => ['guests.view'],
                'restricted_to_properties' => true,
                'property_ids' => [$this->north->getKey()],
            ])
            ->assertOk()
            ->assertJsonPath('data.restricted_to_properties', true)
            ->assertJsonPath('data.property_ids', [$this->north->getKey()]);

        $access = $this->app->make(AccessControl::class);
        $access->flushMemo();
        $held = $access->permissionsFor($agent, $this->organization);
        $this->assertContains('revenue.view', $held);
        $this->assertNotContains('guests.view', $held);
        $this->assertSame([$this->north->getKey()], $access->restrictedPropertyIds($agent, $this->organization));

        // And it holds where it matters: the member sees one property.
        $this->actingAsUser($agent, $this->organization);
        $this->assertSame(['North Room'], array_column($this->getJson('/api/v1/properties')->assertOk()->json('data'), 'name'));
        $this->getJson('/api/v1/guests')->assertForbidden();
    }

    public function test_nobody_gives_more_than_they_hold(): void
    {
        $manager = $this->createUser($this->organization, [RoleRegistry::PROPERTY_MANAGER]);
        $this->grant($manager, ['users.view', 'users.update']);
        $agent = $this->createUser($this->organization, [RoleRegistry::RESERVATIONS_AGENT]);

        $this->actingAsUser($manager, $this->organization);

        $this->patchJson('/api/v1/team/'.$this->seatOf($agent)->getKey(), ['allow' => ['ledger.post']])
            ->assertUnprocessable()->assertJsonValidationErrors('allow');

        $this->patchJson('/api/v1/team/'.$this->seatOf($agent)->getKey(), [
            'role_ids' => [$this->role(RoleRegistry::ORGANIZATION_ADMIN)->getKey()],
        ])->assertUnprocessable()->assertJsonValidationErrors('role_ids');

        $this->assertNotContains('ledger.post', $this->app->make(AccessControl::class)->permissionsFor($agent, $this->organization));
    }

    public function test_nobody_changes_their_own_access(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson('/api/v1/team/'.$this->seatOf($this->admin)->getKey(), ['deny' => ['financials.view']])
            ->assertUnprocessable();

        $this->postJson('/api/v1/team/'.$this->seatOf($this->admin)->getKey().'/deactivate')
            ->assertUnprocessable();
    }

    public function test_the_account_keeps_someone_who_can_manage_its_team(): void
    {
        // The platform acts without a membership of its own, so it could
        // otherwise take the last admin's rights away.
        $platform = User::query()->create([
            'first_name' => 'Platform', 'last_name' => 'Owner', 'email' => 'platform@example.test',
            'password' => 'password-for-tests-1234', 'status' => 'active',
        ]);
        $platform->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        $this->actingAsUser($platform, $this->organization);
        $admin = $this->seatOf($this->admin);

        $this->patchJson('/api/v1/team/'.$admin->getKey(), [
            'role_ids' => [$this->role(RoleRegistry::STAFF)->getKey()],
        ])->assertUnprocessable()->assertJsonValidationErrors('role_ids');

        $this->postJson('/api/v1/team/'.$admin->getKey().'/deactivate')->assertUnprocessable();

        // With a second admin in place, the first can be changed.
        $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);
        $this->patchJson('/api/v1/team/'.$admin->getKey(), [
            'role_ids' => [$this->role(RoleRegistry::STAFF)->getKey()],
        ])->assertOk();
    }

    public function test_an_invitation_carries_the_access_it_was_given(): void
    {
        Notification::fake();

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/team/invitations', [
                'email' => 'Cleaner@Example.test',
                'first_name' => 'Maya',
                'role_ids' => [$this->role(RoleRegistry::CLEANER)->getKey()],
                'deny' => ['documents.view'],
                'restricted_to_properties' => true,
                'property_ids' => [$this->south->getKey()],
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'cleaner@example.test');

        $this->assertSame(['cleaner@example.test'], array_column($this->getJson('/api/v1/team')->json('data.invitations'), 'email'));

        $invitation = Invitation::query()->sole();
        $membership = $this->app->make(InvitationService::class)->accept($invitation, ['password' => 'a-strong-password-123']);
        $this->app->make(TenantContext::class)->set($this->organization);

        $member = $membership->user;
        $access = $this->app->make(AccessControl::class);
        $access->flushMemo();
        $this->assertSame([$this->south->getKey()], $access->restrictedPropertyIds($member, $this->organization));
        $this->assertNotContains('documents.view', $access->permissionsFor($member, $this->organization));
        $this->assertContains('tasks.view_own', $access->permissionsFor($member, $this->organization));
    }

    public function test_an_invitation_can_be_withdrawn(): void
    {
        Notification::fake();
        $this->actingAsUser($this->admin, $this->organization)->postJson('/api/v1/team/invitations', [
            'email' => 'agent@example.test', 'role_ids' => [$this->role(RoleRegistry::RESERVATIONS_AGENT)->getKey()],
        ])->assertCreated();

        $this->deleteJson('/api/v1/team/invitations/'.Invitation::query()->sole()->getKey())->assertOk();

        $this->assertSame([], $this->getJson('/api/v1/team')->json('data.invitations'));
    }

    public function test_switching_someone_off_stops_their_access_and_on_brings_it_back(): void
    {
        $agent = $this->createUser($this->organization, [RoleRegistry::RESERVATIONS_AGENT]);
        $membership = $this->seatOf($agent);
        $access = $this->app->make(AccessControl::class);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/team/'.$membership->getKey().'/deactivate')->assertOk()->assertJsonPath('data.status', 'suspended');
        $access->flushMemo();
        $this->assertSame([], $access->permissionsFor($agent, $this->organization));

        $this->postJson('/api/v1/team/'.$membership->getKey().'/reactivate')->assertOk()->assertJsonPath('data.status', 'active');
        $access->flushMemo();
        $this->assertContains('reservations.view', $access->permissionsFor($agent, $this->organization));
    }

    public function test_someone_without_team_rights_sees_none_of_it(): void
    {
        $agent = $this->createUser($this->organization, [RoleRegistry::RESERVATIONS_AGENT]);

        $this->actingAsUser($agent, $this->organization);
        $this->getJson('/api/v1/team')->assertForbidden();
        $this->patchJson('/api/v1/team/'.$this->seatOf($this->admin)->getKey(), ['deny' => ['guests.view']])->assertForbidden();
        $this->postJson('/api/v1/team/invitations', ['email' => 'x@example.test', 'role_ids' => []])->assertForbidden();
    }

    public function test_another_accounts_member_is_out_of_reach(): void
    {
        ['user' => $elsewhere] = $this->createTenantWithAdmin();
        $other = Membership::query()->withoutGlobalScope('organization')->where('user_id', $elsewhere->getKey())->sole();

        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson('/api/v1/team/'.$other->getKey(), ['deny' => ['guests.view']])
            ->assertNotFound();
    }

    // ------------------------------------------------------------------

    private function seatOf(User $user): Membership
    {
        return Membership::query()->withoutGlobalScope('organization')
            ->where('user_id', $user->getKey())
            ->where('organization_id', $this->organization->getKey())
            ->sole();
    }

    private function role(string $slug): Role
    {
        return Role::query()->withoutGlobalScope('organization')->availableTo($this->organization)->where('slug', $slug)->firstOrFail();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function grant(User $user, array $permissions): void
    {
        $membership = $this->seatOf($user);
        $ids = Permission::query()->whereIn('name', $permissions)->pluck('id')->all();
        $membership->permissionOverrides()->syncWithoutDetaching(array_fill_keys($ids, ['effect' => 'allow']));
        $membership->touch();
        $this->app->make(AccessControl::class)->forget($membership);
        $this->app->make(AccessControl::class)->flushMemo();
    }
}
