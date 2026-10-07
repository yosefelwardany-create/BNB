<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Organization\Models\Organization;
use App\Domain\Owners\Models\Owner;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Account administration for the platform owner.
 *
 * Two kinds of test here, and the second kind matters more.
 *
 * The first checks the administration works: the owner can list client
 * accounts, suspend one, reinstate it, leave a private note and read the
 * platform's health.
 *
 * The second checks it cannot be reached by anybody else. Platform
 * administration is outside the tenant permission system precisely so that no
 * client can grant its way in, and that claim is worth nothing unless something
 * tries. So these assert that an organization admin — the most powerful role a
 * client account can hold — gets a 404 from every route here.
 *
 * 404 rather than 403 throughout: the shape of the administration API is not
 * something a client's credentials should be able to map by watching which
 * paths answer differently.
 */
class PlatformConsoleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $operator;

    private User $tenantAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        ['organization' => $this->organization, 'user' => $this->tenantAdmin] = $this->createTenantWithAdmin();

        // A platform owner with no membership anywhere. Deliberately: the
        // administration must not require a seat in a client's company.
        $this->operator = User::query()->create([
            'first_name' => 'Platform',
            'last_name' => 'Operator',
            'email' => 'operator@platform.test',
            'password' => 'password-for-tests-1234',
            'status' => 'active',
        ]);

        $this->operator->forceFill([
            'is_platform_admin' => true,
            'email_verified_at' => now(),
        ])->save();
    }

    private function asOperator(): static
    {
        return $this->actingAs($this->operator->fresh(), 'sanctum');
    }

    // ---------------------------------------------------------------------
    // The boundary
    // ---------------------------------------------------------------------

    public function test_a_tenant_administrator_cannot_reach_any_administration_route(): void
    {
        $this->actingAsUser($this->tenantAdmin, $this->organization);

        // Every kind of route, not a sample: a gate that holds on the list
        // endpoint and leaks on an action is not a gate.
        $routes = [
            ['get', 'overview'],
            ['get', 'health'],
            ['get', 'growth'],
            ['get', 'organizations'],
            ['get', 'organizations/'.$this->organization->getKey()],
            ['get', 'organizations/'.$this->organization->getKey().'/users'],
            ['get', 'users'],
            ['get', 'settings'],
            ['get', 'audit/platform'],
            ['get', 'audit/tenants'],
        ];

        foreach ($routes as [$method, $path]) {
            $this->{$method}('/api/v1/platform/'.$path)
                ->assertNotFound();
        }

        $this->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/suspend', [
            'reason' => 'Trying it on.',
        ])->assertNotFound();

        $this->postJson('/api/v1/platform/organizations', ['organization_name' => 'Mine'])->assertNotFound();
    }

    public function test_the_retired_console_routes_no_longer_exist(): void
    {
        // Plans, trials, overrides, announcements and support sessions went
        // with the subscription model. Even the owner gets nothing from them.
        $this->asOperator()->getJson('/api/v1/platform/plans')->assertNotFound();
        $this->asOperator()->getJson('/api/v1/platform/announcements')->assertNotFound();
        $this->asOperator()->getJson('/api/v1/platform/impersonations')->assertNotFound();
        $this->asOperator()->getJson('/api/v1/platform/vocabulary')->assertNotFound();

        $id = $this->organization->getKey();

        $this->asOperator()->postJson("/api/v1/platform/organizations/{$id}/plan", ['plan_id' => null])->assertNotFound();
        $this->asOperator()->postJson("/api/v1/platform/organizations/{$id}/trial", [])->assertNotFound();
        $this->asOperator()->postJson("/api/v1/platform/organizations/{$id}/overrides", [])->assertNotFound();
        $this->asOperator()->postJson("/api/v1/platform/organizations/{$id}/impersonate", ['reason' => 'x'])->assertNotFound();
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/v1/platform/overview')->assertUnauthorized();
    }

    public function test_the_administration_runs_outside_any_tenant(): void
    {
        // The SPA sends X-Organization on every request. If the administration
        // honoured it, every count would be filtered to one account.
        $other = $this->createOrganization(['name' => 'Another Company']);

        $this->asOperator()
            ->withHeader('X-Organization', $this->organization->getKey())
            ->getJson('/api/v1/platform/organizations')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertNotSame($other->getKey(), $this->organization->getKey());
    }

    // ---------------------------------------------------------------------
    // Client accounts
    // ---------------------------------------------------------------------

    public function test_it_lists_accounts_with_their_seat_count_and_no_plan(): void
    {
        $response = $this->asOperator()
            ->getJson('/api/v1/platform/organizations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->organization->getKey())
            ->assertJsonPath('data.0.users_count', 1);

        $this->assertArrayNotHasKey('plan', $response->json('data.0'));
        $this->assertArrayNotHasKey('trial_ends_at', $response->json('data.0'));
        $this->assertArrayNotHasKey('effective_limits', $response->json('data.0'));
    }

    public function test_suspending_an_account_stops_access_and_keeps_every_record(): void
    {
        $propertiesBefore = DB::table('properties')->count();

        $this->asOperator()
            ->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/suspend', [
                'reason' => 'Agreement terminated by the client.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspension_reason', 'Agreement terminated by the client.');

        // Nothing was deleted. This is the guarantee, not a side effect.
        $this->assertSame($propertiesBefore, DB::table('properties')->count());
        $this->assertDatabaseHas('organizations', [
            'id' => $this->organization->getKey(),
            'status' => 'suspended',
        ]);

        // And the client can no longer work.
        $this->actingAsUser($this->tenantAdmin->fresh(), $this->organization->fresh())
            ->getJson('/api/v1/properties')
            ->assertForbidden();

        // While the owner still can: a suspended account's data is managed,
        // not abandoned.
        $this->asOperator()
            ->withHeader('X-Organization', $this->organization->getKey())
            ->getJson('/api/v1/properties')
            ->assertOk();
    }

    public function test_suspension_requires_a_reason(): void
    {
        $this->asOperator()
            ->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/suspend', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_suspended_account_can_be_reinstated(): void
    {
        $id = $this->organization->getKey();

        $this->asOperator()->postJson("/api/v1/platform/organizations/{$id}/suspend", [
            'reason' => 'Investigating a complaint.',
        ])->assertOk();

        $this->asOperator()->postJson("/api/v1/platform/organizations/{$id}/reinstate", [
            'reason' => 'Complaint resolved.',
        ])->assertOk()
            ->assertJsonPath('data.suspension_reason', null)
            ->assertJsonPath('data.status', 'active');

        $this->actingAsUser($this->tenantAdmin->fresh(), $this->organization->fresh())
            ->getJson('/api/v1/properties')
            ->assertOk();
    }

    public function test_the_audit_trail_records_what_the_platform_did_to_an_account(): void
    {
        $this->asOperator()
            ->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/suspend', [
                'reason' => 'Terms breach.',
            ])->assertOk();

        // Written into the *client's* audit trail, because they are the ones
        // entitled to the record.
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $this->organization->getKey(),
            'action' => 'organization.suspended',
        ]);

        // And into the platform's, so the owner can read what the platform did
        // without querying across every account. Both, deliberately: neither
        // copy should depend on the other existing.
        $this->assertDatabaseHas('platform_audit_logs', [
            'organization_id' => $this->organization->getKey(),
            'action' => 'organization.suspended',
            'actor_email' => 'operator@platform.test',
        ]);
    }

    public function test_a_platform_note_is_never_visible_to_the_client(): void
    {
        $id = $this->organization->getKey();

        $this->asOperator()->patchJson("/api/v1/platform/organizations/{$id}", [
            'platform_notes' => 'Hostex token expires in March. Contact is unresponsive.',
        ])->assertOk()->assertJsonPath('data.platform_notes', 'Hostex token expires in March. Contact is unresponsive.');

        // The client's own view of itself must not carry it.
        $response = $this->actingAsUser($this->tenantAdmin, $this->organization)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->assertStringNotContainsString('unresponsive', $response->getContent() ?: '');
    }

    public function test_the_account_detail_describes_the_clients_onboarding_state(): void
    {
        $this->asOperator()
            ->getJson('/api/v1/platform/organizations/'.$this->organization->getKey())
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'name', 'status'],
                'meta' => [
                    'counts' => ['properties', 'channel_accounts'],
                    'client' => ['account_holder', 'agreement', 'properties_without_ownership', 'client_logins'],
                ],
            ]);
    }

    // ---------------------------------------------------------------------
    // Platform administration itself
    // ---------------------------------------------------------------------

    public function test_the_last_platform_administrator_cannot_be_demoted(): void
    {
        $this->asOperator()
            ->patchJson('/api/v1/platform/users/'.$this->operator->getKey(), [
                'is_platform_admin' => false,
                'reason' => 'Testing the guard.',
            ])
            ->assertStatus(422);

        $this->assertTrue((bool) $this->operator->fresh()->is_platform_admin);
    }

    public function test_granting_platform_administration_is_audited(): void
    {
        $this->asOperator()
            ->patchJson('/api/v1/platform/users/'.$this->tenantAdmin->getKey(), [
                'is_platform_admin' => true,
                'reason' => 'Joined the platform team.',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_platform_admin', true);

        // Recorded in the platform's own trail, because granting platform
        // administration belongs to no organization.
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'platform.admin_granted',
            'actor_email' => 'operator@platform.test',
        ]);

        $this->asOperator()
            ->getJson('/api/v1/platform/audit/platform')
            ->assertOk()
            ->assertJsonPath('data.0.action', 'platform.admin_granted');
    }

    public function test_an_ordinary_user_token_is_not_treated_as_a_platform_owner(): void
    {
        $token = $this->tenantAdmin->createToken('Their own laptop')->plainTextToken;

        $this->withToken($token)
            ->withHeader('X-Organization', $this->organization->getKey())
            ->getJson('/api/v1/platform/overview')
            ->assertNotFound();

        $operatorToken = $this->operator->createToken('Operator laptop')->plainTextToken;

        // The guard memoises the first user it resolves for the whole test, so
        // without this the request below would travel on the previous token. A
        // real server builds a fresh container per request and has no such
        // carry-over.
        $this->app['auth']->forgetGuards();

        $this->withToken($operatorToken)
            ->getJson('/api/v1/platform/overview')
            ->assertOk();
    }

    // ---------------------------------------------------------------------
    // Settings, health
    // ---------------------------------------------------------------------

    public function test_settings_refuse_a_key_that_does_not_exist(): void
    {
        $this->asOperator()
            ->putJson('/api/v1/platform/settings', [
                'settings' => ['not_a_real_setting' => true],
            ])
            ->assertStatus(422);
    }

    public function test_retired_settings_are_refused_too(): void
    {
        // The subscription vocabulary is gone from the registry, so a stale
        // client cannot quietly write it back.
        $this->asOperator()
            ->putJson('/api/v1/platform/settings', [
                'settings' => ['signups_enabled' => false, 'default_trial_days' => 14],
            ])
            ->assertStatus(422);
    }

    public function test_settings_round_trip(): void
    {
        $this->asOperator()
            ->putJson('/api/v1/platform/settings', [
                'settings' => ['support_email' => 'help@insharo.test'],
            ])
            ->assertOk();

        $response = $this->asOperator()->getJson('/api/v1/platform/settings')->assertOk();

        $settings = collect($response->json('data'))->keyBy('key');

        $this->assertSame('help@insharo.test', $settings['support_email']['value']);
    }

    public function test_health_reports_which_integrations_are_simulated(): void
    {
        $response = $this->asOperator()->getJson('/api/v1/platform/health')->assertOk();

        // The honesty surface for whoever runs the platform. Every bundled
        // provider is a local simulation and must say so with a reason.
        $this->assertFalse($response->json('data.integrations.payments.is_live'));
        $this->assertNotNull($response->json('data.integrations.payments.simulation_reason'));
        $this->assertFalse($response->json('data.integrations.locks.is_live'));
        $this->assertNotNull($response->json('data.integrations.locks.simulation_reason'));

        $channels = collect($response->json('data.integrations.channels'));

        $this->assertTrue($channels->firstWhere('channel', 'airbnb')['is_live'] === false);
        $this->assertTrue($channels->firstWhere('channel', 'ical')['is_live']);
    }

    public function test_one_login_can_be_made_the_accounts_only_login_and_it_can_only_read(): void
    {
        // The tenant's administrator and a second staff login, as an account
        // looked before the managed service.
        $staff = $this->createUser($this->organization, [RoleRegistry::RESERVATIONS_AGENT]);

        $membership = $this->membershipOf($this->tenantAdmin, $this->organization);

        $this->asOperator()
            ->postJson("/api/v1/platform/organizations/{$this->organization->getKey()}/logins/{$membership->getKey()}/sole-client", [
                'reason' => 'One login per client account',
            ])
            ->assertOk()
            ->assertJsonPath('meta.suspended', [$staff->email]);

        $membership->refresh()->load('roles');

        $this->assertSame('owner', $membership->default_portal);
        $this->assertTrue((bool) $membership->restricted_to_properties);
        $this->assertSame([RoleRegistry::CLIENT], $membership->roles->pluck('slug')->all());
        $this->assertSame('suspended', $this->membershipOf($staff, $this->organization)->status->value);

        // The account holder reads as this login.
        $holder = $this->withoutTenantScope(fn () => Owner::query()
            ->where('organization_id', $this->organization->getKey())
            ->where('is_account_holder', true)
            ->firstOrFail());
        $this->assertSame($this->tenantAdmin->getKey(), $holder->user_id);

        // And it can no longer change anything.
        $this->actingAsUser($this->tenantAdmin, $this->organization)
            ->postJson('/api/v1/properties', ['name' => 'Should not exist'])
            ->assertForbidden();

        // The suspended login cannot reach the account at all.
        $this->actingAsUser($staff, $this->organization)
            ->getJson('/api/v1/portal/owner/summary')
            ->assertForbidden();

        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'organization.sole_client_login']);
    }

    public function test_making_a_sole_login_requires_a_reason_and_a_login_from_that_account(): void
    {
        $membership = $this->membershipOf($this->tenantAdmin, $this->organization);

        $this->asOperator()
            ->postJson("/api/v1/platform/organizations/{$this->organization->getKey()}/logins/{$membership->getKey()}/sole-client")
            ->assertStatus(422);

        ['organization' => $other] = $this->createTenantWithAdmin();

        $this->asOperator()
            ->postJson("/api/v1/platform/organizations/{$other->getKey()}/logins/{$membership->getKey()}/sole-client", [
                'reason' => 'Wrong account',
            ])
            ->assertStatus(422);

        // Nothing changed.
        $this->assertSame('active', $membership->fresh()->status->value);
        $this->assertNotSame('owner', $membership->fresh()->default_portal);
    }

    public function test_a_tenant_administrator_cannot_make_anybody_the_sole_login(): void
    {
        $membership = $this->membershipOf($this->tenantAdmin, $this->organization);

        $this->actingAsUser($this->tenantAdmin, $this->organization)
            ->postJson("/api/v1/platform/organizations/{$this->organization->getKey()}/logins/{$membership->getKey()}/sole-client", [
                'reason' => 'Trying',
            ])
            ->assertNotFound();
    }
}
