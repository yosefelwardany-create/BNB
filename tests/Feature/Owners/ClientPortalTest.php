<?php

declare(strict_types=1);

namespace Tests\Feature\Owners;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Services\ClientAccounts;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\Permission;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Where a client's access stops, over HTTP.
 *
 * A client reads their own properties, calendar and financials through the
 * portal and can do nothing else: no staff route answers them, no write goes
 * through whatever the route, no other account's records can be reached by
 * naming them, and nothing operational or personal about guests leaks through
 * the portal's own responses.
 */
class ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Owner $holder;

    private User $client;

    private Property $property;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        [
            'organization' => $this->organization,
            'owner' => $this->holder,
            'user' => $this->client,
        ] = $this->createClientOrganization(['base_currency' => 'CAD']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Harbour Loft',
            'currency' => 'CAD',
            'base_rate' => 10000,
            'cleaning_fee' => 5000,
            'max_occupancy' => 4,
            'settings' => ['hostex' => ['url' => 'https://airbnb.example/rooms/1', 'shelf_status' => 'listed', 'applied' => ['secret' => 'x']]],
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'CAD',
            'minimum_nights' => 1,
        ]);

        // The factory bypasses PropertyService, so attribute the property the
        // way the listener would have.
        $this->app->make(ClientAccounts::class)->attachProperty($this->property);
        $this->app->make(AccessControl::class)->flushMemo();
    }

    private function asClient(): static
    {
        return $this->actingAsUser($this->client->fresh(), $this->organization);
    }

    // ------------------------------------------------------------------
    // Nothing but the portal
    // ------------------------------------------------------------------

    public function test_a_client_cannot_reach_any_staff_route(): void
    {
        $this->book(2, 5);

        foreach ([
            'properties', 'properties/'.$this->property->getKey(),
            'reservations', 'calendar?from='.CarbonImmutable::today()->toDateString().'&to='.CarbonImmutable::today()->addDays(7)->toDateString(),
            'channels', 'channel-listings', 'conversations', 'guests', 'owners',
            'owner-statements', 'payments', 'hostex-transactions', 'revenue/summary', 'reports',
            'properties/'.$this->property->getKey().'/agent',
        ] as $path) {
            $status = $this->asClient()->getJson('/api/v1/'.$path)->getStatusCode();

            $this->assertContains($status, [403, 404], "A client reached GET {$path} ({$status}).");
        }

        $this->asClient()->getJson('/api/v1/platform/organizations')->assertNotFound();
    }

    public function test_a_client_cannot_write_even_when_handed_a_permission(): void
    {
        // Belt and braces: give the client a permission a mistake might grant,
        // and the read-only middleware still refuses the write.
        $membership = $this->membershipOf($this->client, $this->organization);
        $permission = Permission::query()->where('name', 'calendar.update')->firstOrFail();
        $membership->permissionOverrides()->attach($permission->getKey(), ['effect' => 'allow']);
        $membership->touch();
        $this->app->make(AccessControl::class)->forget($membership);

        $this->asClient()->postJson('/api/v1/calendar/blocks', [
            'property_id' => $this->property->getKey(),
            'kind' => 'manual',
            'start_date' => CarbonImmutable::today()->addDays(20)->toDateString(),
            'end_date' => CarbonImmutable::today()->addDays(22)->toDateString(),
        ])->assertForbidden()
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'read-only'));

        $this->assertDatabaseCount('calendar_blocks', 0);

        // Including writes aimed at the portal itself, which only accepts
        // reads: refused before the request is even routed.
        $this->asClient()->postJson('/api/v1/portal/owner/summary', [])->assertStatus(405);
        $this->asClient()->patchJson('/api/v1/properties/'.$this->property->getKey(), ['name' => 'Mine now'])->assertForbidden();
        $this->asClient()->deleteJson('/api/v1/channels/01HZZZZZZZZZZZZZZZZZZZZZZZ')->assertForbidden();

        $this->assertSame('Harbour Loft', $this->property->fresh()->name);
    }

    public function test_a_client_keeps_their_own_account_security_actions(): void
    {
        $this->asClient()
            ->patchJson('/api/v1/auth/profile', ['first_name' => 'Renamed'])
            ->assertOk();

        $this->assertSame('Renamed', $this->client->fresh()->first_name);

        $this->asClient()->postJson('/api/v1/auth/mfa/begin', ['password' => 'password-for-tests-1234'])->assertOk();
        $this->asClient()->postJson('/api/v1/auth/logout')->assertOk();
    }

    public function test_a_client_cannot_name_another_account(): void
    {
        $other = $this->createClientOrganization(['name' => 'Somebody Else']);

        $this->actingAs($this->client->fresh(), 'sanctum')
            ->withHeader('X-Organization', $other['organization']->getKey())
            ->getJson('/api/v1/portal/owner/summary')
            // The same message as "does not exist", so the header cannot be
            // used to discover which accounts exist.
            ->assertForbidden()
            ->assertJsonPath('message', 'The requested organization does not exist.');
    }

    public function test_a_client_cannot_read_another_accounts_records_by_id(): void
    {
        $other = $this->createClientOrganization(['name' => 'Somebody Else', 'base_currency' => 'CAD']);

        $this->actingForOrganization($other['organization']);
        $theirProperty = Property::factory()->active()->create([
            'organization_id' => $other['organization']->getKey(),
            'currency' => 'CAD',
            'base_rate' => 20000,
            'max_occupancy' => 2,
        ]);
        $theirStatement = OwnerStatement::query()->create([
            'organization_id' => $other['organization']->getKey(),
            'owner_id' => $other['owner']->getKey(),
            'reference' => 'OS-'.Str::random(8),
            'period_start' => CarbonImmutable::today()->startOfMonth()->toDateString(),
            'period_end' => CarbonImmutable::today()->endOfMonth()->toDateString(),
            'currency' => 'CAD',
            'payout_amount' => 1000,
            'net_due' => 1000,
            'closing_balance' => 1000,
        ]);
        $theirStatement->forceFill(['status' => OwnerStatement::STATUS_SENT, 'sent_at' => now()])->save();

        $this->asClient()->getJson('/api/v1/portal/owner/properties/'.$theirProperty->getKey())->assertNotFound();
        $this->asClient()->getJson('/api/v1/portal/owner/statements/'.$theirStatement->getKey().'/document')->assertNotFound();

        $response = $this->asClient()->getJson('/api/v1/portal/owner/properties')->assertOk();
        $this->assertStringNotContainsString($theirProperty->getKey(), $response->getContent());
    }

    // ------------------------------------------------------------------
    // What the portal shows, and withholds
    // ------------------------------------------------------------------

    public function test_the_properties_endpoint_carries_nothing_operational(): void
    {
        $response = $this->asClient()->getJson('/api/v1/portal/owner/properties')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Harbour Loft', $response->json('data.0.name'));
        $this->assertSame('https://airbnb.example/rooms/1', $response->json('data.0.listing.channel_url'));

        $body = $response->getContent();

        foreach (['applied', 'agent', 'access', 'wifi', 'door_code', 'internal_notes', 'bot_url', 'webhook', 'credentials', 'access_token', 'hostex_overrides'] as $forbidden) {
            $this->assertStringNotContainsString("\"{$forbidden}\"", $body, "The client property payload carries {$forbidden}.");
        }

        $this->asClient()->getJson('/api/v1/portal/owner/properties/'.$this->property->getKey())
            ->assertOk()
            ->assertJsonPath('data.id', $this->property->getKey())
            ->assertJsonMissingPath('data.agent')
            ->assertJsonMissingPath('data.access')
            ->assertJsonMissingPath('data.hostex');
    }

    public function test_the_calendar_shows_the_stay_and_not_the_guest(): void
    {
        $reservation = $this->book(2, 5);
        $guest = $reservation->guest;

        $from = CarbonImmutable::today()->toDateString();
        $to = CarbonImmutable::today()->addDays(10)->toDateString();

        $response = $this->asClient()
            ->getJson("/api/v1/portal/owner/calendar?from={$from}&to={$to}")
            ->assertOk()
            ->assertJsonStructure(['from', 'to', 'listings', 'reservations', 'blocks']);

        $this->assertCount(1, $response->json('listings'));
        $this->assertCount(1, $response->json('reservations'));
        $this->assertSame(3, $response->json('reservations.0.nights'));

        $body = $response->getContent();
        $this->assertStringNotContainsString((string) $guest->display_name, $body);
        $this->assertStringNotContainsString((string) $guest->email, $body);
        $this->assertStringNotContainsString('guest_name', $body);
        $this->assertStringNotContainsString('confirmation_code', $body);
        $this->assertStringNotContainsString('balance_due', $body);
    }

    public function test_the_client_sees_the_public_name_never_the_internal_one(): void
    {
        $this->property->forceFill(['internal_name' => 'HL-01 staff shorthand'])->save();
        $this->book(2, 5);

        $from = CarbonImmutable::today()->toDateString();
        $to = CarbonImmutable::today()->addDays(10)->toDateString();

        $calendar = $this->asClient()->getJson("/api/v1/portal/owner/calendar?from={$from}&to={$to}")->assertOk();
        $this->assertSame('Harbour Loft', $calendar->json('listings.0.property_name'));

        $financials = $this->asClient()->getJson("/api/v1/portal/owner/financials?from={$from}&to={$to}")->assertOk();
        $this->assertStringNotContainsString('staff shorthand', $calendar->getContent().$financials->getContent());
    }

    public function test_a_second_client_login_reads_the_same_portfolio(): void
    {
        // A partner with a login of their own but no owner record: a client
        // login resolves to the account holder.
        $partner = $this->createUser($this->organization, [RoleRegistry::CLIENT], ['email' => 'partner@example.test']);
        Membership::query()->where('user_id', $partner->getKey())->update(['restricted_to_properties' => false]);

        $this->actingAsUser($partner, $this->organization)
            ->getJson('/api/v1/portal/owner/summary')
            ->assertOk()
            ->assertJsonPath('data.owner.id', $this->holder->getKey());

        // While a staff login gets nothing from the portal.
        $staff = $this->createUser($this->organization, [RoleRegistry::PROPERTY_MANAGER]);

        $this->actingAsUser($staff, $this->organization)
            ->getJson('/api/v1/portal/owner/summary')
            ->assertForbidden();
    }

    public function test_the_platform_owner_sees_the_account_as_its_client_does(): void
    {
        // The client view: the same read-only portal, answered with the
        // account holder's portfolio and nothing operational.
        $this->actingAsPlatformAdmin($this->organization)
            ->getJson('/api/v1/portal/owner/summary')
            ->assertOk()
            ->assertJsonPath('data.owner.id', $this->holder->getKey());

        $response = $this->actingAsPlatformAdmin($this->organization)
            ->getJson('/api/v1/portal/owner/properties')
            ->assertOk();

        $this->assertSame('Harbour Loft', $response->json('data.0.name'));
        $this->assertStringNotContainsString('"door_code"', $response->getContent());
        $this->assertStringNotContainsString('"internal_notes"', $response->getContent());
    }

    public function test_the_platform_owner_manages_the_clients_account_by_header(): void
    {
        $this->book(2, 5);

        // Reads and writes inside the client's account, authenticated as the
        // owner, with no membership anywhere.
        $this->actingAsPlatformAdmin($this->organization)
            ->getJson('/api/v1/reservations')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $created = $this->actingAsPlatformAdmin($this->organization)
            ->postJson('/api/v1/calendar/blocks', [
                'property_id' => $this->property->getKey(),
                'kind' => 'maintenance',
                'start_date' => CarbonImmutable::today()->addDays(30)->toDateString(),
                'end_date' => CarbonImmutable::today()->addDays(32)->toDateString(),
            ])->assertCreated();

        // Landed in the client's account, and the response says which.
        $this->assertDatabaseHas('calendar_blocks', [
            'id' => $created->json('data.id'),
            'organization_id' => $this->organization->getKey(),
        ]);
        $created->assertHeader('X-Organization', $this->organization->getKey());
    }

    public function test_a_request_with_no_account_context_is_refused(): void
    {
        // A platform owner who names no account gets nothing, not everything.
        $admin = $this->createPlatformAdmin();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/properties')
            ->assertForbidden();
    }

    private function book(int $inDays, int $outDays): Reservation
    {
        $this->actingForOrganization($this->organization);

        return $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $this->listing,
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
