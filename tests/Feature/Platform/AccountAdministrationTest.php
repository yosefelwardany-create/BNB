<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\JuanLopezDemoSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Deleting a client account and resetting a login's password, from Accounts.
 *
 * Deleting is irreversible, so the tests are mostly about what it refuses and
 * what it leaves alone.
 */
class AccountAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->createPlatformAdmin(['email' => 'operator@platform.test']);
    }

    private function asOperator(): static
    {
        return $this->actingAs($this->operator->fresh(), 'sanctum');
    }

    private function suspend(Organization $organization): void
    {
        DB::table('organizations')->where('id', $organization->getKey())->update(['status' => 'suspended']);
    }

    // ------------------------------------------------------------------
    // Deleting an account
    // ------------------------------------------------------------------

    public function test_an_account_in_use_cannot_be_deleted(): void
    {
        ['organization' => $organization] = $this->createTenantWithAdmin(['name' => 'Harbour Homes']);

        $this->asOperator()
            ->deleteJson("/api/v1/platform/organizations/{$organization->getKey()}", [
                'confirm_name' => 'Harbour Homes',
                'reason' => 'Client left',
            ])
            ->assertStatus(422);

        $this->assertTrue(DB::table('organizations')->where('id', $organization->getKey())->exists());
    }

    public function test_the_name_must_be_typed_back_exactly(): void
    {
        ['organization' => $organization] = $this->createTenantWithAdmin(['name' => 'Harbour Homes']);
        $this->suspend($organization);

        $this->asOperator()
            ->deleteJson("/api/v1/platform/organizations/{$organization->getKey()}", [
                'confirm_name' => 'harbour homes',
                'reason' => 'Client left',
            ])
            ->assertStatus(422);

        $this->asOperator()
            ->deleteJson("/api/v1/platform/organizations/{$organization->getKey()}", ['confirm_name' => 'Harbour Homes'])
            ->assertStatus(422);

        $this->assertTrue(DB::table('organizations')->where('id', $organization->getKey())->exists());
    }

    public function test_a_suspended_account_is_deleted_with_everything_in_it_and_nothing_else(): void
    {
        Artisan::call('amenities:sync');

        // An account with real depth: properties, bookings, ledger, inbox.
        $this->seed(JuanLopezDemoSeeder::class);
        $doomed = app(TenantContext::class)->withoutScope(
            fn () => Organization::query()->where('slug', JuanLopezDemoSeeder::SLUG)->firstOrFail(),
        );

        ['organization' => $kept] = $this->createTenantWithAdmin(['name' => 'Kept Account']);

        // A login only in the doomed account goes; one that also works in
        // another account stays.
        $this->actingForOrganization($doomed);
        $only = $this->createUser($doomed, [RoleRegistry::CLIENT], ['email' => 'only@doomed.test']);
        $shared = $this->createUser($doomed, [RoleRegistry::CLIENT], ['email' => 'shared@both.test']);
        $this->actingForOrganization($kept);
        DB::table('memberships')->insert([
            'id' => (string) str()->ulid(),
            'organization_id' => $kept->getKey(),
            'user_id' => $shared->getKey(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->suspend($doomed);

        $this->asOperator()
            ->deleteJson("/api/v1/platform/organizations/{$doomed->getKey()}", [
                'confirm_name' => 'Juan Lopez',
                'reason' => 'Sample account no longer needed',
            ])
            ->assertOk()
            ->assertJsonPath('meta.logins_removed', ['only@doomed.test']);

        $this->assertFalse(DB::table('organizations')->where('id', $doomed->getKey())->exists());
        foreach (['properties', 'reservations', 'journal_lines', 'conversations', 'owners', 'audit_logs', 'memberships'] as $table) {
            $this->assertSame(0, DB::table($table)->where('organization_id', $doomed->getKey())->count(), $table);
        }

        $this->assertFalse(User::withTrashed()->whereKey($only->getKey())->exists());
        $this->assertTrue(User::query()->whereKey($shared->getKey())->exists());
        $this->assertTrue(User::query()->whereKey($this->operator->getKey())->exists());
        $this->assertTrue(DB::table('organizations')->where('id', $kept->getKey())->exists());

        // The deletion stays on record, with the account's name.
        $this->assertDatabaseHas('platform_audit_logs', [
            'action' => 'organization.deleted',
            'organization_name' => 'Juan Lopez',
        ]);
    }

    public function test_a_client_administrator_cannot_delete_any_account(): void
    {
        ['organization' => $organization, 'user' => $admin] = $this->createTenantWithAdmin(['name' => 'Harbour Homes']);
        $this->suspend($organization);

        $this->actingAsUser($admin, $organization)
            ->deleteJson("/api/v1/platform/organizations/{$organization->getKey()}", [
                'confirm_name' => 'Harbour Homes',
                'reason' => 'Trying',
            ])
            ->assertNotFound();

        $this->assertTrue(DB::table('organizations')->where('id', $organization->getKey())->exists());
    }

    // ------------------------------------------------------------------
    // Resetting a password
    // ------------------------------------------------------------------

    public function test_a_new_password_is_set_shown_once_and_ends_every_session(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $user->createToken('phone');
        $membership = $this->membershipOf($user, $organization);

        $response = $this->asOperator()
            ->postJson("/api/v1/platform/organizations/{$organization->getKey()}/logins/{$membership->getKey()}/password", [
                'method' => 'generate',
                'reason' => 'Client locked out',
            ])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $password = (string) $response->json('meta.password');

        $this->assertSame(16, strlen($password));
        $this->assertTrue(Hash::check($password, (string) $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'user.password_set']);
        // The password itself is never written to the audit.
        $this->assertSame(0, DB::table('platform_audit_logs')->where('context', 'like', '%'.$password.'%')->count());
    }

    public function test_a_reset_link_can_be_sent_instead(): void
    {
        Notification::fake();

        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $membership = $this->membershipOf($user, $organization);
        $before = (string) $user->password;

        $this->asOperator()
            ->postJson("/api/v1/platform/organizations/{$organization->getKey()}/logins/{$membership->getKey()}/password", [
                'method' => 'link',
                'reason' => 'Client forgot it',
            ])
            ->assertOk()
            ->assertJsonMissingPath('meta.password');

        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertSame($before, (string) $user->fresh()->password);
    }

    public function test_a_platform_owner_or_another_accounts_login_is_refused(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $membership = $this->membershipOf($user, $organization);

        // A platform owner holding a seat in the account.
        $user->forceFill(['is_platform_admin' => true])->save();

        $this->asOperator()
            ->postJson("/api/v1/platform/organizations/{$organization->getKey()}/logins/{$membership->getKey()}/password", [
                'method' => 'generate',
                'reason' => 'Trying',
            ])
            ->assertStatus(422);

        // A login from another account, named under this one.
        ['organization' => $other] = $this->createTenantWithAdmin();

        $this->asOperator()
            ->postJson("/api/v1/platform/organizations/{$other->getKey()}/logins/{$membership->getKey()}/password", [
                'method' => 'generate',
                'reason' => 'Trying',
            ])
            ->assertNotFound();
    }

    public function test_a_client_administrator_cannot_reset_anybody(): void
    {
        ['organization' => $organization, 'user' => $admin] = $this->createTenantWithAdmin();
        $membership = $this->membershipOf($admin, $organization);

        $this->actingAsUser($admin, $organization)
            ->postJson("/api/v1/platform/organizations/{$organization->getKey()}/logins/{$membership->getKey()}/password", [
                'method' => 'generate',
                'reason' => 'Trying',
            ])
            ->assertNotFound();
    }
}
