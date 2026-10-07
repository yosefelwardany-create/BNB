<?php

declare(strict_types=1);

namespace Tests\Feature\Owners;

use App\Domain\Owners\Models\ManagementAgreement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Models\PropertyOwnership;
use App\Domain\Owners\Services\ClientAccounts;
use App\Domain\Properties\Enums\PropertyType;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a client account is, in data, and what provisioning may and may not do.
 *
 * The rules with teeth: provisioning creates and never overwrites; a staff
 * login is never converted by anything automatic; a conversion is refused
 * until a platform owner exists; and nothing a registrant sends can make them
 * anything but a client.
 */
class ClientAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisioning_creates_a_complete_client_account(): void
    {
        Notification::fake();

        $result = $this->createClientOrganization(['name' => 'Harbour Lets']);

        $organization = $result['organization'];

        $this->assertSame('active', $organization->status->value);
        $this->assertNull($organization->trial_ends_at);

        // The account holder stands for the client.
        $holder = Owner::query()->forOrganization($organization)->accountHolder()->firstOrFail();
        $this->assertSame($result['user']->getKey(), $holder->user_id);
        $this->assertTrue($holder->portal_enabled);

        // The agreement says exactly what the commission is charged on.
        $agreement = ManagementAgreement::query()->forOrganization($organization)
            ->where('owner_id', $holder->getKey())->whereNull('property_id')->firstOrFail();
        $this->assertSame('percent_of_revenue', $agreement->commission_model);
        $this->assertEqualsWithDelta(10.0, (float) $agreement->commission_rate, 0.0001);
        $this->assertTrue($agreement->commission_on_accommodation);
        $this->assertFalse($agreement->commission_on_fees);
        $this->assertFalse($agreement->commission_on_taxes);
        $this->assertFalse($agreement->deduct_channel_commission_first);
        $this->assertFalse($agreement->deduct_payment_fees_first);

        // The login holds the client role and nothing else.
        $membership = $result['membership'];
        $this->assertSame([RoleRegistry::CLIENT], $membership->roles->pluck('slug')->all());
        $this->assertSame('owner', $membership->default_portal);
        $this->assertTrue((bool) $membership->restricted_to_properties);
        $this->assertSame([], $this->app->make(AccessControl::class)->permissionsFor($result['user'], $organization));
        $this->assertFalse($result['user']->isPlatformAdmin());
    }

    public function test_a_property_created_in_a_client_account_belongs_to_the_holder_at_once(): void
    {
        ['organization' => $organization, 'owner' => $holder, 'user' => $user] = $this->createClientOrganization();

        $property = $this->property('Sea View');

        $ownership = PropertyOwnership::query()->where('property_id', $property->getKey())->firstOrFail();

        $this->assertSame($holder->getKey(), $ownership->owner_id);
        $this->assertEqualsWithDelta(100.0, (float) $ownership->ownership_percentage, 0.0001);
        $this->assertNull($ownership->ends_on);

        // And the client can see it: the membership restriction follows.
        $access = $this->app->make(AccessControl::class);
        $access->flushMemo();
        $this->assertSame([$property->getKey()], $access->restrictedPropertyIds($user, $organization));
    }

    public function test_provisioning_is_idempotent_and_overwrites_nothing(): void
    {
        ['organization' => $organization, 'owner' => $holder] = $this->createClientOrganization();

        $property = $this->property('Shared Villa');

        // A real third-party owner with a deliberate share and a negotiated
        // agreement, entered by a person.
        $thirdParty = Owner::query()->create([
            'organization_id' => $organization->getKey(),
            'type' => 'individual',
            'first_name' => 'Rui',
            'last_name' => 'Costa',
            'email' => 'rui@example.test',
            'payout_currency' => 'CAD',
        ]);

        PropertyOwnership::query()->where('property_id', $property->getKey())->delete();

        DB::table('property_ownerships')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->getKey(),
            'owner_id' => $thirdParty->getKey(),
            'property_id' => $property->getKey(),
            'ownership_percentage' => 60,
            'starts_on' => CarbonImmutable::today()->subYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $negotiated = ManagementAgreement::query()->forOrganization($organization)
            ->where('owner_id', $holder->getKey())->whereNull('property_id')->firstOrFail();
        $negotiated->forceFill(['commission_rate' => 12.5])->save();

        $before = [
            'owners' => Owner::query()->forOrganization($organization)->count(),
            'ownerships' => PropertyOwnership::query()->forOrganization($organization)->count(),
            'agreements' => ManagementAgreement::query()->forOrganization($organization)->count(),
        ];

        Artisan::call('clients:provision');
        Artisan::call('clients:provision');

        $this->assertSame($before['owners'], Owner::query()->forOrganization($organization)->count());
        $this->assertSame($before['agreements'], ManagementAgreement::query()->forOrganization($organization)->count());

        // The property already has an owner, so the holder was not given it.
        $this->assertSame($before['ownerships'], PropertyOwnership::query()->forOrganization($organization)->count());
        $this->assertSame(
            60.0,
            (float) PropertyOwnership::query()->where('property_id', $property->getKey())->value('ownership_percentage'),
        );

        // And the negotiated rate stands.
        $this->assertEqualsWithDelta(12.5, (float) $negotiated->fresh()->commission_rate, 0.0001);
    }

    public function test_provisioning_a_legacy_tenant_creates_the_holder_and_attributes_unowned_properties(): void
    {
        ['organization' => $organization, 'user' => $admin] = $this->createTenantWithAdmin(['name' => 'Legacy Co']);

        $this->actingForOrganization($organization);
        $first = $this->property('First');
        $second = $this->property('Second');

        Artisan::call('clients:provision', ['--organization' => $organization->getKey()]);

        $holder = Owner::query()->forOrganization($organization)->accountHolder()->firstOrFail();

        $this->assertSame('Legacy Co', $holder->display_name);
        // The earliest member's address, since the organization recorded none.
        $this->assertSame($admin->email, $holder->email);

        $this->assertSame(2, PropertyOwnership::query()->forOrganization($organization)
            ->where('owner_id', $holder->getKey())->whereIn('property_id', [$first->getKey(), $second->getKey()])->count());

        // But the administrator keeps their role. Nothing automatic converts a
        // login: which logins are the client's is a person's decision.
        $this->assertSame(
            [RoleRegistry::ORGANIZATION_ADMIN],
            $this->membershipOf($admin, $organization)->roles->pluck('slug')->all(),
        );
    }

    public function test_the_holder_is_paid_in_the_currency_the_properties_earn_in(): void
    {
        // The live shape: a USD account letting a CAD flat. A statement
        // refuses to mix currencies, so a USD holder would have every
        // statement refused.
        ['organization' => $organization] = $this->createTenantWithAdmin(['name' => 'USD Co', 'base_currency' => 'USD']);

        $this->actingForOrganization($organization);
        $this->app->make(PropertyService::class)->create([
            'name' => 'Toronto Room '.Str::random(4),
            'property_type' => PropertyType::Apartment,
            'address_line_1' => '1 Harbour Road',
            'postal_code' => 'M5V 1A1',
            'city' => 'Toronto',
            'country_code' => 'CA',
            'max_occupancy' => 1,
            'base_rate' => 6000,
            'currency' => 'CAD',
        ]);

        Artisan::call('clients:provision', ['--organization' => $organization->getKey()]);

        $holder = Owner::query()->forOrganization($organization)->accountHolder()->firstOrFail();

        $this->assertSame('CAD', $holder->payout_currency);
    }

    public function test_a_login_is_converted_only_deliberately_and_only_once_an_owner_exists(): void
    {
        ['organization' => $organization, 'user' => $admin] = $this->createTenantWithAdmin();

        Artisan::call('clients:provision', ['--organization' => $organization->getKey()]);

        // No platform owner yet: refused, so nobody can remove the last
        // administrative access before anybody else has it.
        $this->assertSame(1, Artisan::call('clients:convert-login', [
            'organization' => $organization->getKey(),
            'email' => $admin->email,
        ]));

        $this->assertSame(
            [RoleRegistry::ORGANIZATION_ADMIN],
            $this->membershipOf($admin, $organization)->roles->pluck('slug')->all(),
        );

        $this->createPlatformAdmin();

        $this->assertSame(0, Artisan::call('clients:convert-login', [
            'organization' => $organization->getKey(),
            'email' => $admin->email,
            '--reason' => 'This is the client, not staff.',
        ]));

        $membership = $this->membershipOf($admin, $organization)->load('roles');

        $this->assertSame([RoleRegistry::CLIENT], $membership->roles->pluck('slug')->all());
        $this->assertSame('owner', $membership->default_portal);
        $this->assertTrue((bool) $membership->restricted_to_properties);

        $holder = Owner::query()->forOrganization($organization)->accountHolder()->firstOrFail();
        $this->assertSame($admin->getKey(), $holder->user_id);

        $this->app->make(AccessControl::class)->flushMemo();
        $this->assertSame([], $this->app->make(AccessControl::class)->permissionsFor($admin->fresh(), $organization));

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->getKey(),
            'action' => 'membership.converted_to_client',
        ]);
    }

    public function test_a_platform_owner_is_never_converted(): void
    {
        ['organization' => $organization] = $this->createTenantWithAdmin();
        $owner = $this->createPlatformAdmin();

        $this->expectException(\RuntimeException::class);

        $this->app->make(ClientAccounts::class)->convertMembership($organization, $owner);
    }

    public function test_registration_creates_a_client_and_ignores_anything_that_would_make_them_more(): void
    {
        config()->set('pms.registration.open', true);
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Self Signup Lets',
            'base_currency' => 'CAD',
            'timezone' => 'America/Toronto',
            'first_name' => 'Sam',
            'last_name' => 'Client',
            'email' => 'sam@client.test',
            'password' => 'a-long-password-12345',
            'password_confirmation' => 'a-long-password-12345',
            // None of these are fields; all of them are ignored.
            'is_platform_admin' => true,
            'roles' => ['organization-admin', 'super-admin'],
            'role' => 'organization-admin',
        ])->assertCreated();

        $user = User::query()->where('email', 'sam@client.test')->firstOrFail();
        $organizationId = $response->json('organization.id');

        $this->assertFalse($user->isPlatformAdmin());

        $membership = $this->membershipOf($user, \App\Domain\Organization\Models\Organization::query()->findOrFail($organizationId))->load('roles');

        $this->assertSame([RoleRegistry::CLIENT], $membership->roles->pluck('slug')->all());
        $this->assertSame('owner', $membership->default_portal);

        $this->assertTrue(Owner::query()->forOrganization($organizationId)->accountHolder()->where('user_id', $user->getKey())->exists());
        $this->assertTrue(ManagementAgreement::query()->forOrganization($organizationId)->where('commission_rate', 10)->exists());
    }

    public function test_the_platform_admin_flag_is_never_mass_assignable(): void
    {
        $user = User::query()->create([
            'first_name' => 'Mass',
            'last_name' => 'Assigned',
            'email' => 'mass@example.test',
            'password' => 'password-for-tests-1234',
            'status' => 'active',
            'is_platform_admin' => true,
        ]);

        $this->assertFalse($user->fresh()->isPlatformAdmin());
    }

    public function test_the_grant_command_is_the_sanctioned_path_and_is_audited(): void
    {
        $user = User::query()->create([
            'first_name' => 'Future',
            'last_name' => 'Owner',
            'email' => 'future@insharo.test',
            'password' => 'password-for-tests-1234',
            'status' => 'active',
        ]);

        $this->assertSame(0, Artisan::call('platform:grant-admin', ['email' => 'future@insharo.test', '--reason' => 'Founder.']));
        $this->assertTrue($user->fresh()->isPlatformAdmin());
        $this->assertDatabaseHas('platform_audit_logs', ['action' => 'platform.admin_granted']);

        // The last owner cannot be revoked.
        $this->assertSame(1, Artisan::call('platform:grant-admin', ['email' => 'future@insharo.test', '--revoke' => true]));
        $this->assertTrue($user->fresh()->isPlatformAdmin());
    }

    private function property(string $name): Property
    {
        return $this->app->make(PropertyService::class)->create([
            'name' => $name.' '.Str::random(4),
            'property_type' => PropertyType::Apartment,
            'address_line_1' => '1 Harbour Road',
            'postal_code' => 'M5V 1A1',
            'city' => 'Toronto',
            'country_code' => 'CA',
            'max_occupancy' => 2,
            'base_rate' => 9000,
        ]);
    }
}
