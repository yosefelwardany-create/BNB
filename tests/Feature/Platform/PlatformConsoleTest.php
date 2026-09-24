<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Models\ImpersonationSession;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Models\PlatformAnnouncement;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The platform console.
 *
 * Two kinds of test here, and the second kind matters more.
 *
 * The first checks the console works: an operator can list tenants, suspend one,
 * move it onto a plan and read the platform's health.
 *
 * The second checks the console cannot be reached. Platform administration is
 * outside the tenant permission system precisely so that no tenant can grant its
 * way in, and that claim is worth nothing unless something tries. So these
 * assert that an organization admin — the most powerful role a customer has —
 * gets a 404 from every route here, and that an impersonation token cannot reach
 * the console that issued it.
 *
 * 404 rather than 403 throughout: the shape of the platform console is not
 * something a tenant's credentials should be able to map by watching which paths
 * answer differently.
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

        // A platform operator with no membership anywhere. Deliberately: the
        // console must not require a seat in a customer's company.
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

    public function test_a_tenant_administrator_cannot_reach_any_console_route(): void
    {
        $this->actingAsUser($this->tenantAdmin, $this->organization);

        // Every kind of route, not a sample: a gate that holds on the list
        // endpoint and leaks on an action is not a gate.
        $routes = [
            ['get', 'overview'],
            ['get', 'health'],
            ['get', 'growth'],
            ['get', 'vocabulary'],
            ['get', 'organizations'],
            ['get', 'organizations/'.$this->organization->getKey()],
            ['get', 'plans'],
            ['get', 'users'],
            ['get', 'announcements'],
            ['get', 'settings'],
            ['get', 'impersonations'],
            ['get', 'audit'],
        ];

        foreach ($routes as [$method, $path]) {
            $this->{$method}('/api/v1/platform/'.$path)
                ->assertNotFound();
        }

        $this->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/suspend', [
            'reason' => 'Trying it on.',
        ])->assertNotFound();

        $this->postJson('/api/v1/platform/plans', ['name' => 'Mine'])->assertNotFound();
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/v1/platform/overview')->assertUnauthorized();
    }

    public function test_the_console_runs_outside_any_tenant(): void
    {
        // The SPA sends X-Organization on every request. If the console honoured
        // it, every count would be filtered to one tenant.
        $other = $this->createOrganization(['name' => 'Another Company']);

        $this->asOperator()
            ->withHeader('X-Organization', $this->organization->getKey())
            ->getJson('/api/v1/platform/organizations')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertNotSame($other->getKey(), $this->organization->getKey());
    }

    // ---------------------------------------------------------------------
    // Tenants
    // ---------------------------------------------------------------------

    public function test_it_lists_tenants_with_their_plan_and_seat_count(): void
    {
        $this->asOperator()
            ->getJson('/api/v1/platform/organizations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->organization->getKey())
            ->assertJsonPath('data.0.users_count', 1)
            ->assertJsonPath('data.0.plan', null);
    }

    public function test_suspending_a_tenant_stops_access_and_keeps_every_record(): void
    {
        $propertiesBefore = DB::table('properties')->count();

        $this->asOperator()
            ->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/suspend', [
                'reason' => 'Non-payment after three reminders.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspension_reason', 'Non-payment after three reminders.');

        // Nothing was deleted. This is the guarantee, not a side effect.
        $this->assertSame($propertiesBefore, DB::table('properties')->count());
        $this->assertDatabaseHas('organizations', [
            'id' => $this->organization->getKey(),
            'status' => 'suspended',
        ]);

        // And the customer can no longer work.
        $this->actingAsUser($this->tenantAdmin->fresh(), $this->organization->fresh())
            ->getJson('/api/v1/properties')
            ->assertForbidden();
    }

    public function test_suspension_requires_a_reason(): void
    {
        $this->asOperator()
            ->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/suspend', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_suspended_tenant_can_be_reinstated(): void
    {
        $id = $this->organization->getKey();

        $this->asOperator()->postJson("/api/v1/platform/organizations/{$id}/suspend", [
            'reason' => 'Investigating a chargeback.',
        ])->assertOk();

        $this->asOperator()->postJson("/api/v1/platform/organizations/{$id}/reinstate", [
            'reason' => 'Chargeback resolved.',
        ])->assertOk()->assertJsonPath('data.suspension_reason', null);

        $this->actingAsUser($this->tenantAdmin->fresh(), $this->organization->fresh())
            ->getJson('/api/v1/properties')
            ->assertOk();
    }

    public function test_the_audit_trail_records_what_the_platform_did_to_a_tenant(): void
    {
        $this->asOperator()
            ->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/suspend', [
                'reason' => 'Terms breach.',
            ])->assertOk();

        // Written into the *customer's* audit trail, because they are the ones
        // entitled to the record.
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $this->organization->getKey(),
            'action' => 'organization.suspended',
        ]);

        // And into the platform's, so an operator can read what the platform did
        // without querying across every tenant. Both, deliberately: neither copy
        // should depend on the other existing.
        $this->assertDatabaseHas('platform_audit_logs', [
            'organization_id' => $this->organization->getKey(),
            'action' => 'organization.suspended',
            'actor_email' => 'operator@platform.test',
        ]);
    }

    public function test_a_platform_note_is_never_visible_to_the_tenant(): void
    {
        $id = $this->organization->getKey();

        $this->asOperator()->patchJson("/api/v1/platform/organizations/{$id}", [
            'platform_notes' => 'Chasing payment. Contact is unresponsive.',
        ])->assertOk()->assertJsonPath('data.platform_notes', 'Chasing payment. Contact is unresponsive.');

        // The tenant's own view of itself must not carry it.
        $response = $this->actingAsUser($this->tenantAdmin, $this->organization)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->assertStringNotContainsString('unresponsive', $response->getContent() ?: '');
    }

    // ---------------------------------------------------------------------
    // Plans, and whether they bite
    // ---------------------------------------------------------------------

    public function test_a_plan_cap_refuses_the_record_that_would_exceed_it(): void
    {
        $plan = $this->plan(['max_properties' => 1]);

        $this->asOperator()
            ->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/plan', [
                'plan_id' => $plan->getKey(),
            ])->assertOk();

        $this->actingAsUser($this->tenantAdmin, $this->organization->fresh());

        $this->postJson('/api/v1/properties', $this->propertyPayload('First'))->assertCreated();

        // 402, not 422: nothing is wrong with the request, somebody needs to pay.
        $this->postJson('/api/v1/properties', $this->propertyPayload('Second'))
            ->assertStatus(402);
    }

    public function test_a_feature_the_plan_omits_is_refused_at_the_route(): void
    {
        // A plan with no features at all: the gate must fail closed.
        $plan = $this->plan(['features' => []]);

        $this->asOperator()
            ->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/plan', [
                'plan_id' => $plan->getKey(),
            ])->assertOk();

        $this->actingAsUser($this->tenantAdmin, $this->organization->fresh())
            ->getJson('/api/v1/channels')
            ->assertStatus(402);
    }

    public function test_an_override_lifts_a_cap_for_one_tenant(): void
    {
        $plan = $this->plan(['max_properties' => 1, 'features' => []]);
        $id = $this->organization->getKey();

        $this->asOperator()->postJson("/api/v1/platform/organizations/{$id}/plan", [
            'plan_id' => $plan->getKey(),
        ])->assertOk();

        $this->asOperator()->postJson("/api/v1/platform/organizations/{$id}/overrides", [
            'limits' => ['max_properties' => 5],
            'features' => ['channels' => true],
            'reason' => 'Negotiated at renewal.',
        ])->assertOk();

        $this->actingAsUser($this->tenantAdmin, $this->organization->fresh());

        $this->postJson('/api/v1/properties', $this->propertyPayload('One'))->assertCreated();
        $this->postJson('/api/v1/properties', $this->propertyPayload('Two'))->assertCreated();
        $this->getJson('/api/v1/channels')->assertOk();
    }

    public function test_downgrading_over_a_cap_reports_the_breach_rather_than_deleting_anything(): void
    {
        $this->actingAsUser($this->tenantAdmin, $this->organization);
        $this->postJson('/api/v1/properties', $this->propertyPayload('A'))->assertCreated();
        $this->postJson('/api/v1/properties', $this->propertyPayload('B'))->assertCreated();

        $plan = $this->plan(['max_properties' => 1]);

        $this->asOperator()
            ->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/plan', [
                'plan_id' => $plan->getKey(),
            ])
            ->assertOk()
            ->assertJsonPath('meta.breaches.max_properties.used', 2)
            ->assertJsonPath('meta.breaches.max_properties.limit', 1);

        // Both properties survive. A plan change must never destroy records.
        $this->assertSame(2, DB::table('properties')
            ->where('organization_id', $this->organization->getKey())
            ->count());
    }

    public function test_a_plan_in_use_cannot_be_retired(): void
    {
        $plan = $this->plan([]);
        $id = $this->organization->getKey();

        $this->asOperator()->postJson("/api/v1/platform/organizations/{$id}/plan", [
            'plan_id' => $plan->getKey(),
        ])->assertOk();

        $this->asOperator()
            ->deleteJson('/api/v1/platform/plans/'.$plan->getKey())
            ->assertStatus(422);

        $this->assertDatabaseHas('plans', ['id' => $plan->getKey(), 'deleted_at' => null]);
    }

    public function test_a_trial_cannot_be_set_in_the_past(): void
    {
        $this->asOperator()
            ->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/trial', [
                'trial_ends_at' => now()->subDay()->toIso8601String(),
            ])
            ->assertStatus(422);
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

    // ---------------------------------------------------------------------
    // Impersonation
    // ---------------------------------------------------------------------

    public function test_a_support_session_can_read_and_cannot_write(): void
    {
        $token = $this->beginSupportSession();

        // Reads as the customer, with every scope and policy applying.
        $this->withToken($token)
            ->withHeader('X-Organization', $this->organization->getKey())
            ->getJson('/api/v1/properties')
            ->assertOk();

        // And cannot change anything, through any route.
        $this->withToken($token)
            ->withHeader('X-Organization', $this->organization->getKey())
            ->postJson('/api/v1/properties', $this->propertyPayload('Sneaky'))
            ->assertForbidden();

        $this->withToken($token)
            ->withHeader('X-Organization', $this->organization->getKey())
            ->patchJson('/api/v1/organization/plan', [])
            ->assertStatus(405);
    }

    public function test_an_ordinary_user_token_is_not_treated_as_a_support_session(): void
    {
        // The regression this test exists for: an ordinary token is issued with
        // the `*` ability, and Sanctum's `can()` honours it — so checking
        // `can('platform:impersonate-read')` answered true for every customer's
        // own token. That refused all their writes and hid the console from a
        // platform administrator using a real token rather than a cookie.
        $token = $this->tenantAdmin->createToken('Their own laptop')->plainTextToken;

        $this->withToken($token)
            ->withHeader('X-Organization', $this->organization->getKey())
            ->postJson('/api/v1/properties', $this->propertyPayload('Theirs'))
            ->assertCreated();

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

    public function test_a_support_session_cannot_reach_the_platform_console(): void
    {
        $token = $this->beginSupportSession();

        // The one escalation this design has to close: a borrowed token must not
        // be able to start another borrowing. Two independent barriers stop it,
        // and the read-only one happens to be reached first — a 403 that says
        // "this session cannot write" rather than confirming the console exists.
        $this->withToken($token)
            ->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/impersonate', [
                'reason' => 'Escalating from inside a session.',
            ])
            ->assertForbidden();

        // Reading the console is refused by the console's own gate, as a 404.
        $this->withToken($token)->getJson('/api/v1/platform/overview')->assertNotFound();

        $this->assertDatabaseCount('impersonation_sessions', 1);
    }

    public function test_a_support_session_requires_a_reason(): void
    {
        $this->asOperator()
            ->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/impersonate', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_the_customer_can_see_who_looked_at_their_account(): void
    {
        $this->beginSupportSession('Investigating a duplicated statement.');

        $this->actingAsUser($this->tenantAdmin, $this->organization)
            ->getJson('/api/v1/organization/support-sessions')
            ->assertOk()
            ->assertJsonPath('data.0.reason', 'Investigating a duplicated statement.')
            ->assertJsonPath('data.0.operator.email', 'operator@platform.test')
            ->assertJsonPath('data.0.was_read_only', true);
    }

    public function test_ending_a_session_revokes_its_token_and_nothing_else(): void
    {
        $token = $this->beginSupportSession();
        $session = ImpersonationSession::query()->latest('started_at')->firstOrFail();

        // The customer's own session keeps working, which is the point: closing
        // a support tab must not sign a customer out of their own company.
        $customerToken = $this->tenantAdmin->createToken('Their own')->plainTextToken;

        $this->asOperator()
            ->deleteJson('/api/v1/platform/impersonations/'.$session->getKey())
            ->assertOk()
            ->assertJsonPath('data.is_open', false);

        // Drop the operator again, so the assertions below really travel on the
        // tokens rather than on the guard actingAs left behind.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->withHeader('X-Organization', $this->organization->getKey())
            ->getJson('/api/v1/properties')
            ->assertUnauthorized();

        $this->withToken($customerToken)
            ->withHeader('X-Organization', $this->organization->getKey())
            ->getJson('/api/v1/properties')
            ->assertOk();
    }

    public function test_a_platform_administrator_cannot_be_impersonated(): void
    {
        $this->asOperator()
            ->patchJson('/api/v1/platform/users/'.$this->tenantAdmin->getKey(), [
                'is_platform_admin' => true,
                'reason' => 'Joined the platform team.',
            ])->assertOk();

        // Their only member is now a platform admin, so there is nobody to view
        // it as — impersonating one would launder one operator's actions through
        // another's identity.
        $this->asOperator()
            ->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/impersonate', [
                'reason' => 'Trying to borrow an operator account.',
                'user_id' => $this->tenantAdmin->getKey(),
            ])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // Announcements, settings, health
    // ---------------------------------------------------------------------

    public function test_an_announcement_reaches_the_tenants_it_names_and_no_others(): void
    {
        $other = $this->createOrganization(['name' => 'Not Addressed']);
        $otherAdmin = $this->createUser($other, [RoleRegistry::ORGANIZATION_ADMIN]);

        $this->asOperator()->postJson('/api/v1/platform/announcements', [
            'title' => 'Channel sync maintenance',
            'body' => 'Sunday, 02:00 to 04:00 UTC.',
            'level' => 'warning',
            'audience' => 'specific',
            'organization_ids' => [$this->organization->getKey()],
            'is_published' => true,
        ])->assertCreated();

        $this->actingAsUser($this->tenantAdmin, $this->organization)
            ->getJson('/api/v1/organization/announcements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Channel sync maintenance');

        $this->actingAsUser($otherAdmin, $other)
            ->getJson('/api/v1/organization/announcements')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_unpublished_announcement_reaches_nobody(): void
    {
        PlatformAnnouncement::query()->create([
            'title' => 'Draft',
            'body' => 'Not ready.',
            'audience' => 'all',
            'is_published' => false,
        ]);

        $this->actingAsUser($this->tenantAdmin, $this->organization)
            ->getJson('/api/v1/organization/announcements')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_settings_refuse_a_key_that_does_not_exist(): void
    {
        $this->asOperator()
            ->putJson('/api/v1/platform/settings', [
                'settings' => ['not_a_real_setting' => true],
            ])
            ->assertStatus(422);
    }

    public function test_settings_round_trip(): void
    {
        $this->asOperator()
            ->putJson('/api/v1/platform/settings', [
                'settings' => ['signups_enabled' => false, 'default_trial_days' => 14],
            ])
            ->assertOk();

        $response = $this->asOperator()->getJson('/api/v1/platform/settings')->assertOk();

        $settings = collect($response->json('data'))->keyBy('key');

        $this->assertFalse($settings['signups_enabled']['value']);
        $this->assertSame(14, $settings['default_trial_days']['value']);
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

    public function test_a_tenant_sees_its_own_plan_and_usage(): void
    {
        $plan = $this->plan(['max_properties' => 3, 'features' => ['channels']]);

        $this->asOperator()
            ->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/plan', [
                'plan_id' => $plan->getKey(),
            ])->assertOk();

        $this->actingAsUser($this->tenantAdmin, $this->organization->fresh())
            ->getJson('/api/v1/organization/plan')
            ->assertOk()
            ->assertJsonPath('data.is_metered', true)
            ->assertJsonPath('data.usage.max_properties.limit', 3)
            ->assertJsonPath('data.features.channels.enabled', true)
            ->assertJsonPath('data.features.upsells.enabled', false)
            ->assertJsonPath('data.features.upsells.source', 'plan');
    }

    public function test_a_tenant_on_no_plan_is_unmetered_and_told_so(): void
    {
        $this->actingAsUser($this->tenantAdmin, $this->organization)
            ->getJson('/api/v1/organization/plan')
            ->assertOk()
            ->assertJsonPath('data.is_metered', false)
            ->assertJsonPath('data.plan', null)
            ->assertJsonPath('data.usage.max_properties.limit', null)
            ->assertJsonPath('data.features.channels.enabled', true)
            ->assertJsonPath('data.features.channels.source', 'unmetered');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function plan(array $attributes): Plan
    {
        return Plan::query()->create(array_merge([
            'name' => 'Test Plan',
            'slug' => 'test-plan-'.uniqid(),
            'price_amount' => 9900,
            'currency' => 'EUR',
        ], $attributes));
    }

    private function beginSupportSession(string $reason = 'Support request 1234.'): string
    {
        $response = $this->asOperator()
            ->postJson('/api/v1/platform/organizations/'.$this->organization->getKey().'/impersonate', [
                'reason' => $reason,
            ])
            ->assertCreated()
            ->assertJsonPath('meta.read_only', true);

        // Drop the acting-as operator. `actingAs` sets a user directly on the
        // guard, and a guard that already has a user never looks at the bearer
        // token — so without this the tests that follow would exercise the
        // operator's own session while believing they were using the
        // impersonation token, and would pass for the wrong reason.
        $this->app['auth']->forgetGuards();

        return $response->json('data.token');
    }

    /**
     * @return array<string, mixed>
     */
    private function propertyPayload(string $name): array
    {
        return [
            'name' => $name,
            'property_type' => 'apartment',
            'address_line_1' => '1 Test Street',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'max_occupancy' => 2,
            'base_rate' => 10000,
        ];
    }
}
