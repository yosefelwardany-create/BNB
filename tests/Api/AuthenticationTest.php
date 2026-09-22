<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\LoginHistory;
use App\Domain\Users\Support\RoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_organization_can_be_registered_with_its_first_administrator(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Coastal Stays',
            'base_currency' => 'EUR',
            'timezone' => 'Europe/Lisbon',
            'first_name' => 'Ana',
            'last_name' => 'Ferreira',
            'email' => 'ana@coastalstays.test',
            'password' => 'a-long-enough-password-42',
            'password_confirmation' => 'a-long-enough-password-42',
        ]);

        $response->assertCreated()
            ->assertJsonPath('organization.name', 'Coastal Stays')
            ->assertJsonPath('organization.base_currency', 'EUR')
            ->assertJsonPath('user.email', 'ana@coastalstays.test');

        $organization = Organization::query()->where('slug', 'coastal-stays')->firstOrFail();

        // The founder gets full administrative access, and the organization is
        // immediately usable: its chart of accounts exists.
        $this->actingForOrganization($organization);

        $this->assertSame(1, $organization->memberships()->count());
        $this->assertGreaterThan(
            0,
            \App\Domain\Accounting\Models\LedgerAccount::query()
                ->where('organization_id', $organization->getKey())
                ->count(),
        );
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Weak Co',
            'first_name' => 'Sam',
            'email' => 'sam@weak.test',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_refuses_an_email_that_already_exists(): void
    {
        $organization = $this->createOrganization();
        $existing = $this->createUser($organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Second Company',
            'first_name' => 'Someone',
            'email' => $existing->email,
            'password' => 'a-long-enough-password-42',
            'password_confirmation' => 'a-long-enough-password-42',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_member_can_sign_in_and_receive_a_scoped_api_token(): void
    {
        $organization = $this->createOrganization(['name' => 'Harbour Lets']);
        $user = $this->createUser($organization, [RoleRegistry::PROPERTY_MANAGER], [
            'email' => 'manager@harbour.test',
            'password' => 'a-long-enough-password-42',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'manager@harbour.test',
            'password' => 'a-long-enough-password-42',
            'device_name' => 'integration-test',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['user', 'organizations', 'token'])
            ->assertJsonPath('organizations.0.name', 'Harbour Lets');

        // The token is bound to the single organization the user belongs to,
        // so it cannot be replayed against another tenant.
        $token = $user->tokens()->firstOrFail();
        $this->assertContains('organization:'.$organization->getKey(), $token->abilities);
    }

    public function test_bad_credentials_are_rejected_and_recorded(): void
    {
        $organization = $this->createOrganization();
        $this->createUser($organization, [RoleRegistry::STAFF], [
            'email' => 'staff@example.test',
            'password' => 'a-long-enough-password-42',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'staff@example.test',
            'password' => 'the-wrong-password',
        ])->assertStatus(422);

        $attempt = LoginHistory::query()->where('email', 'staff@example.test')->firstOrFail();

        $this->assertFalse($attempt->successful);
        $this->assertSame('invalid_credentials', $attempt->failure_reason);
    }

    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $organization = $this->createOrganization();
        $user = $this->createUser($organization, [RoleRegistry::STAFF], [
            'email' => 'former@example.test',
            'password' => 'a-long-enough-password-42',
        ]);

        $user->forceFill(['status' => 'deactivated'])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'former@example.test',
            'password' => 'a-long-enough-password-42',
        ])->assertStatus(422);
    }

    public function test_me_returns_the_effective_permission_set(): void
    {
        $organization = $this->createOrganization();
        $agent = $this->createUser($organization, [RoleRegistry::RESERVATIONS_AGENT]);

        $response = $this->actingAsUser($agent, $organization)->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('organization.id', $organization->getKey())
            ->assertJsonPath('membership.roles.0.slug', RoleRegistry::RESERVATIONS_AGENT);

        $permissions = $response->json('permissions');

        $this->assertContains('reservations.create', $permissions);
        $this->assertNotContains('ledger.post', $permissions);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_a_user_cannot_act_on_an_organization_they_do_not_belong_to(): void
    {
        $home = $this->createOrganization(['name' => 'Home Company']);
        $user = $this->createUser($home, [RoleRegistry::ORGANIZATION_ADMIN]);

        $foreign = $this->createOrganization(['name' => 'Other Company']);

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Organization', $foreign->getKey())
            ->getJson('/api/v1/auth/me')
            ->assertStatus(403);
    }

    public function test_a_user_in_several_organizations_must_name_one(): void
    {
        $first = $this->createOrganization(['name' => 'First']);
        $user = $this->createUser($first, [RoleRegistry::ORGANIZATION_ADMIN]);

        $second = $this->createOrganization(['name' => 'Second']);
        $this->app->make(\App\Domain\Organization\Services\OrganizationProvisioner::class)
            ->attachUser($second, $user, [RoleRegistry::ACCOUNTANT]);

        // Without a hint the request is refused rather than guessed at.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertStatus(409);

        // Naming one works.
        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Organization', $second->getKey())
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('organization.name', 'Second');
    }

    public function test_sign_out_revokes_the_presented_token(): void
    {
        $organization = $this->createOrganization();
        $user = $this->createUser($organization, [RoleRegistry::STAFF], [
            'email' => 'bye@example.test',
            'password' => 'a-long-enough-password-42',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'bye@example.test',
            'password' => 'a-long-enough-password-42',
            'device_name' => 'phone',
        ])->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }
}
