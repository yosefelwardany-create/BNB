<?php

declare(strict_types=1);

namespace Tests\Feature\Owners;

use App\Domain\Organization\Models\Organization;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Services\OwnerDirectory;
use App\Domain\Properties\Enums\PropertyType;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Where a client's access stops.
 *
 * A client is a customer of the management company, not a member of it. Two
 * boundaries decide whether that holds:
 *
 *  - **What they may do.** The client role holds no staff permission at all.
 *    Everything a client reads comes through the portal endpoints, whose
 *    subject is always the signed-in client.
 *
 *  - **What they may see.** Visibility is a pivot of property ids on the
 *    membership, re-synced whenever a share changes — including on the edit
 *    path, which once forgot and left a seller still reading a flat they no
 *    longer owned.
 */
class OwnerAccessBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $staff;

    public function test_a_client_holds_no_staff_permission_at_all(): void
    {
        $this->tenant();

        $owner = $this->ownerWithLogin('owner@example.test');
        $access = $this->app->make(AccessControl::class);

        $this->assertSame([], $access->permissionsFor($owner['user'], $this->organization));

        foreach ([
            'messages.send', 'messages.view',
            'properties.view', 'reservations.view', 'calendar.view', 'calendar.update',
            'channels.view', 'channels.manage', 'owner_statements.view', 'financials.view',
            'reports.view', 'documents.view', 'reviews.view',
        ] as $permission) {
            $this->assertFalse(
                $access->allows($owner['user'], $permission, $this->organization),
                "A client must not hold {$permission}.",
            );
        }
    }

    public function test_ending_a_share_by_editing_it_stops_the_owner_seeing_the_property(): void
    {
        $this->tenant();

        $yellow = $this->property('Yellow Room');
        $blue = $this->property('Blue Room');

        $owner = $this->ownerWithLogin('owner@example.test');

        $this->attach($owner['owner'], $yellow);
        $this->attach($owner['owner'], $blue);

        $this->app->make(OwnerDirectory::class)->syncPortalProperties($owner['owner']->fresh());

        $this->assertEqualsCanonicalizing(
            [$yellow->getKey(), $blue->getKey()],
            $this->visibleTo($owner['user']),
        );

        $ownership = DB::table('property_ownerships')
            ->where('owner_id', $owner['owner']->getKey())
            ->where('property_id', $blue->getKey())
            ->value('id');

        /*
         * Closed by PATCH rather than through the end route.
         *
         * Both are legitimate ways to finish a share, and only one of them used
         * to re-sync visibility. An owner who sold a flat kept reading its
         * bookings and its revenue until some unrelated call happened to put it
         * right.
         */
        $this->actingAsUser($this->staff, $this->organization);

        $this->patchJson(
            "/api/v1/owners/{$owner['owner']->getKey()}/ownerships/{$ownership}",
            ['ends_on' => CarbonImmutable::yesterday()->toDateString()],
        )->assertOk();

        $this->assertSame([$yellow->getKey()], $this->visibleTo($owner['user']));
    }

    // ---------------------------------------------------------------- fixtures

    private function tenant(): void
    {
        $this->organization = $this->createOrganization();
        $this->staff = $this->createUser($this->organization);
        $this->actingAsUser($this->staff, $this->organization);
    }

    private function property(string $name): Property
    {
        return $this->app->make(PropertyService::class)->create([
            'name' => $name.' '.Str::random(4),
            'property_type' => PropertyType::Apartment,
            'address_line_1' => 'Rua dos Remédios 12',
            'postal_code' => '1100-513',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'max_occupancy' => 2,
            'base_rate' => 9000,
        ]);
    }

    /**
     * @return array{owner: Owner, user: User}
     */
    private function ownerWithLogin(string $email): array
    {
        $owner = Owner::query()->create([
            'organization_id' => $this->organization->getKey(),
            'type' => 'individual',
            'first_name' => 'Owner',
            'last_name' => 'Example',
            'email' => $email,
            'payout_currency' => 'CAD',
        ]);

        $user = $this->createUser($this->organization, [RoleRegistry::OWNER], ['email' => $email]);

        $owner->forceFill(['user_id' => $user->getKey(), 'portal_enabled' => true])->save();

        Membership::query()
            ->where('organization_id', $this->organization->getKey())
            ->where('user_id', $user->getKey())
            ->update(['restricted_to_properties' => true]);

        return ['owner' => $owner->fresh(), 'user' => $user];
    }

    private function attach(Owner $owner, Property $property): void
    {
        DB::table('property_ownerships')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $this->organization->getKey(),
            'owner_id' => $owner->getKey(),
            'property_id' => $property->getKey(),
            'ownership_percentage' => 100,
            'is_primary' => true,
            'starts_on' => CarbonImmutable::today()->subYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function visibleTo(User $user): array
    {
        $access = $this->app->make(AccessControl::class);
        $access->flushMemo();

        return $access->restrictedPropertyIds($user, $this->organization) ?? [];
    }
}
