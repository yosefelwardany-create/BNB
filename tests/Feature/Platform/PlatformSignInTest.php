<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A platform administrator signing in.
 *
 * This existed and did not work. A platform operator holds no membership
 * anywhere — governing the platform does not require a seat in a customer's
 * company, and giving them one would misrepresent how the console's
 * authorisation works — but `auth/me` sat inside the tenant middleware, which
 * refuses any request it cannot resolve an organization for.
 *
 * So the sign-in issued a token, the very next call was refused, and the
 * client did what it should do with a dead session: cleared it and returned
 * the operator to the sign-in screen. From outside it looked like a wrong
 * password. The console was unreachable through the interface entirely.
 *
 * The rule that has not changed is the one worth protecting: `auth/me`
 * describes the session, and every route that returns a tenant's data still
 * resolves an organization or refuses.
 */
class PlatformSignInTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $operator;

    private User $tenantAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        ['organization' => $this->organization, 'user' => $this->tenantAdmin] = $this->createTenantWithAdmin();

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

    // -----------------------------------------------------------------------
    // The bug
    // -----------------------------------------------------------------------

    public function test_a_platform_administrator_can_establish_a_session(): void
    {
        $this->actingAs($this->operator->fresh(), 'sanctum');

        // The call the interface makes immediately after sign-in. Refusing it
        // is indistinguishable, from the browser, from a wrong password.
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('is_platform_admin', true)
            ->assertJsonPath('user.email', 'operator@platform.test')
            // No membership anywhere, stated rather than fabricated.
            ->assertJsonPath('organization', null)
            ->assertJsonPath('membership', null);
    }

    public function test_the_session_grants_the_operator_everything(): void
    {
        $this->actingAs($this->operator->fresh(), 'sanctum');

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('permissions', ['*']);
    }

    public function test_signing_in_and_reading_the_session_works_end_to_end(): void
    {
        // The whole flow the browser performs, not just the half of it that
        // was already passing.
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'operator@platform.test',
            'password' => 'password-for-tests-1234',
            'device_name' => 'admin-web',
        ])->assertOk();

        $this->assertNotNull($login->json('token'));

        // A platform operator belongs to nothing, so the client has no
        // organization to name on the next request.
        $this->assertSame([], $login->json('organizations'));

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$login->json('token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('is_platform_admin', true);
    }

    // -----------------------------------------------------------------------
    // What must not have changed
    // -----------------------------------------------------------------------

    public function test_an_ordinary_session_still_carries_its_organization(): void
    {
        $this->actingAsUser($this->tenantAdmin, $this->organization);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('organization.id', $this->organization->getKey())
            ->assertJsonPath('is_platform_admin', false)
            ->assertJsonPath('organization.name', $this->organization->name);
    }

    public function test_a_tenant_route_still_refuses_a_caller_with_no_organization(): void
    {
        $this->actingAs($this->operator->fresh(), 'sanctum');

        // The relaxation is for the session endpoint only. A route that
        // returns a customer's data must still resolve a tenant or refuse —
        // otherwise "optional" would have quietly become "unscoped".
        $this->getJson('/api/v1/properties')->assertForbidden();
    }

    public function test_a_platform_administrator_acting_for_a_tenant_still_gets_that_tenant(): void
    {
        $this->actingAs($this->operator->fresh(), 'sanctum');

        // Naming an organization resolves it as it always did, so the operator
        // is not cut off from a tenant they legitimately asked for.
        $this->withHeader('X-Organization', $this->organization->getKey())
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('organization.id', $this->organization->getKey())
            ->assertJsonPath('is_platform_admin', true);
    }
}
