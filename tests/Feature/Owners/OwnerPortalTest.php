<?php

declare(strict_types=1);

namespace Tests\Feature\Owners;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The owner portal.
 *
 * An owner is a user of this system with exactly one legitimate subject:
 * themselves. These tests are about the edges of that — that figures are
 * scaled to their share, that they see nothing about other owners, and that
 * guest identities do not leak out through what looks like an innocuous
 * bookings list.
 */
class OwnerPortalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'cleaning_fee' => 5000,
            'max_occupancy' => 4,
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);
    }

    public function test_an_owner_sees_performance_for_the_properties_they_hold(): void
    {
        ['owner' => $owner, 'user' => $user] = $this->ownerWithLogin(100.0);

        $this->book(2, 5);

        $response = $this->actingAsUser($user, $this->organization)
            ->getJson('/api/v1/portal/owner/summary?from='.CarbonImmutable::today()->toDateString()
                .'&to='.CarbonImmutable::today()->addDays(9)->toDateString())
            ->assertOk();

        $this->assertSame($owner->getKey(), $response->json('data.owner.id'));
        $this->assertCount(1, $response->json('data.properties'));
        $this->assertSame(3, $response->json('data.totals.nights_sold'));
        $this->assertGreaterThan(0, $response->json('data.totals.accommodation_revenue.amount'));
    }

    public function test_revenue_is_scaled_to_the_owners_share(): void
    {
        ['user' => $halfOwner] = $this->ownerWithLogin(50.0, 'half@example.test');

        $reservation = $this->book(2, 5);

        $response = $this->actingAsUser($halfOwner, $this->organization)
            ->getJson('/api/v1/portal/owner/summary?from='.CarbonImmutable::today()->toDateString()
                .'&to='.CarbonImmutable::today()->addDays(9)->toDateString())
            ->assertOk();

        // Half the villa is half the revenue. Showing the whole figure invites
        // a conversation that starts "but you told me".
        $this->assertSame(
            (int) round((int) $reservation->accommodation_total / 2),
            $response->json('data.totals.accommodation_revenue.amount'),
        );

        $this->assertEqualsWithDelta(50.0, $response->json('data.properties.0.ownership_percentage'), 0.01);
    }

    public function test_an_owner_sees_nothing_about_another_owners_property(): void
    {
        ['user' => $mine] = $this->ownerWithLogin(100.0, 'mine@example.test');

        // A second property with a different owner entirely.
        $other = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 50000,
            'max_occupancy' => 4,
        ]);

        $otherListing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $other->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);

        $theirOwner = $this->owner('theirs@example.test');
        $this->grantOwnership($theirOwner, $other, 100.0);

        $this->book(2, 5, $otherListing);

        $response = $this->actingAsUser($mine, $this->organization)
            ->getJson('/api/v1/portal/owner/summary')
            ->assertOk();

        $propertyIds = collect($response->json('data.properties'))->pluck('property_id');

        $this->assertFalse($propertyIds->contains($other->getKey()));
        $this->assertStringNotContainsString($theirOwner->getKey(), $response->getContent());
    }

    public function test_upcoming_stays_carry_no_guest_identity(): void
    {
        ['user' => $user] = $this->ownerWithLogin(100.0);

        $reservation = $this->book(5, 8);
        $guest = $reservation->guest;

        $response = $this->actingAsUser($user, $this->organization)
            ->getJson('/api/v1/portal/owner/upcoming')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(3, $response->json('data.0.nights'));

        // An owner is entitled to know their flat is let. Who is sleeping in
        // it is the guest's business and the manager's.
        $body = $response->getContent();
        $this->assertStringNotContainsString((string) $guest->email, $body);
        $this->assertStringNotContainsString((string) $guest->display_name, $body);
        $this->assertNotEmpty($response->json('meta.notice'));
    }

    public function test_only_statements_that_were_sent_are_visible(): void
    {
        ['owner' => $owner, 'user' => $user] = $this->ownerWithLogin(100.0);

        $draft = $this->statement($owner, OwnerStatement::STATUS_DRAFT);
        $sent = $this->statement($owner, OwnerStatement::STATUS_SENT);

        $response = $this->actingAsUser($user, $this->organization)
            ->getJson('/api/v1/portal/owner/statements')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        // A draft is the manager's working figure. Showing it would have
        // owners reconciling against numbers that are still moving.
        $this->assertTrue($ids->contains($sent->getKey()));
        $this->assertFalse($ids->contains($draft->getKey()));
    }

    public function test_the_balance_comes_from_the_statement_the_owner_was_sent(): void
    {
        ['owner' => $owner, 'user' => $user] = $this->ownerWithLogin(100.0);

        // Set before it is issued, because a sent statement's figures are
        // frozen — which is itself the guarantee this test relies on.
        $this->statement($owner, OwnerStatement::STATUS_SENT, closingBalance: 123456);

        $response = $this->actingAsUser($user, $this->organization)
            ->getJson('/api/v1/portal/owner/summary')
            ->assertOk();

        // Read from the statement rather than recomputed: two independent
        // calculations of the same number eventually disagree, and the owner
        // believes whichever is larger.
        $this->assertSame(123456, $response->json('data.balance.closing_balance.amount'));
    }

    public function test_a_login_that_is_not_an_owner_is_told_so_plainly(): void
    {
        $staff = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        // A 403 rather than a 404: the endpoint exists, this caller simply is
        // not an owner.
        $this->actingAsUser($staff, $this->organization)
            ->getJson('/api/v1/portal/owner/summary')
            ->assertForbidden();
    }

    public function test_an_owner_with_no_properties_gets_an_empty_answer_not_an_error(): void
    {
        $owner = $this->owner('nothing@example.test');
        $user = $this->createUser($this->organization, [RoleRegistry::OWNER], ['email' => 'nothing@example.test']);
        $owner->forceFill(['user_id' => $user->getKey(), 'portal_enabled' => true])->save();

        $this->actingAsUser($user, $this->organization)
            ->getJson('/api/v1/portal/owner/summary')
            ->assertOk()
            ->assertJsonCount(0, 'data.properties')
            ->assertJsonPath('data.totals.nights_sold', 0)
            ->assertJsonPath('data.totals.occupancy_rate', 0);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array{owner: Owner, user: User}
     */
    private function ownerWithLogin(float $share, string $email = 'owner@example.test'): array
    {
        $owner = $this->owner($email);

        $this->grantOwnership($owner, $this->property, $share);

        $user = $this->createUser($this->organization, [RoleRegistry::OWNER], ['email' => $email]);

        $owner->forceFill(['user_id' => $user->getKey(), 'portal_enabled' => true])->save();

        return ['owner' => $owner->fresh(), 'user' => $user];
    }

    private function owner(string $email): Owner
    {
        return Owner::query()->create([
            'organization_id' => $this->organization->getKey(),
            'type' => 'individual',
            'first_name' => 'Owner',
            'last_name' => ucfirst(explode('@', $email)[0]),
            'email' => $email,
            'payout_currency' => 'EUR',
        ]);
    }

    private function grantOwnership(Owner $owner, Property $property, float $share): void
    {
        DB::table('property_ownerships')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $this->organization->getKey(),
            'owner_id' => $owner->getKey(),
            'property_id' => $property->getKey(),
            'ownership_percentage' => $share,
            'starts_on' => CarbonImmutable::today()->subYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function statement(Owner $owner, string $status, int $closingBalance = 50000): OwnerStatement
    {
        $statement = OwnerStatement::query()->create([
            'organization_id' => $this->organization->getKey(),
            'owner_id' => $owner->getKey(),
            'reference' => 'OS-'.Str::random(8),
            'period_start' => CarbonImmutable::today()->startOfMonth()->toDateString(),
            'period_end' => CarbonImmutable::today()->endOfMonth()->toDateString(),
            'currency' => 'EUR',
            'payout_amount' => 50000,
            'net_due' => 50000,
            'closing_balance' => $closingBalance,
        ]);

        if ($status !== OwnerStatement::STATUS_DRAFT) {
            $statement->forceFill(['status' => $status, 'sent_at' => now()])->save();
        }

        return $statement->fresh();
    }

    private function book(int $inDays, int $outDays, ?Listing $listing = null): Reservation
    {
        return $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $listing ?? $this->listing,
            checkIn: CarbonImmutable::today()->addDays($inDays),
            checkOut: CarbonImmutable::today()->addDays($outDays),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Henrique',
                'last_name' => 'Ferreira',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
            bookedAt: CarbonImmutable::today(),
        ));
    }
}
