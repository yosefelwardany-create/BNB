<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The channel API over HTTP.
 *
 * The property this file exists to protect is honesty. Every major OTA
 * requires a signed partner agreement before its API can be used, so those
 * channels are served by a local simulation — and the API must say so, on the
 * catalogue and on every verification result, rather than letting a green tick
 * imply that we reached Airbnb.
 *
 * The second property is that credentials go in and never come back out.
 */
class ChannelApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'max_occupancy' => 4,
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);

        $this->admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);
    }

    public function test_the_catalogue_states_which_channels_are_real(): void
    {
        $response = $this->actingAsUser($this->admin, $this->organization)
            ->getJson('/api/v1/channels/available')
            ->assertOk();

        $channels = collect($response->json('data'))->keyBy('channel');

        // Direct bookings come from our own engine; iCal is an open protocol
        // we genuinely speak. Both are live, and neither needs an excuse.
        $this->assertTrue($channels['direct']['is_live']);
        $this->assertNull($channels['direct']['simulation_reason']);
        $this->assertTrue($channels['ical']['is_live']);

        // Airbnb is not, and the response says why in words an operator can
        // act on rather than "not implemented".
        $this->assertFalse($channels['airbnb']['is_live']);
        $this->assertStringContainsString('partner agreement', $channels['airbnb']['simulation_reason']);
    }

    public function test_connecting_a_channel_stores_credentials_that_never_come_back(): void
    {
        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/channels', [
                'channel' => 'airbnb',
                'name' => 'Airbnb — Lisbon portfolio',
                'credentials' => ['client_id' => 'abc', 'client_secret' => 'super-secret'],
                'commission_basis_points' => 1500,
                'collects_payment' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.channel', 'airbnb')
            // Whether credentials exist is all a settings screen needs.
            ->assertJsonPath('data.has_credentials', true)
            ->assertJsonPath('data.collects_payment', true);

        $body = $response->json('data');

        $this->assertEqualsWithDelta(15.0, $body['commission_percent'], 0.0001);

        $this->assertArrayNotHasKey('credentials', $body);
        $this->assertArrayNotHasKey('webhook_secret', $body);
        $this->assertStringNotContainsString('super-secret', $response->getContent());

        // Encrypted at rest, too: the raw column must not contain the secret.
        $raw = DB::table('channel_accounts')
            ->where('id', $body['id'])->value('credentials');

        $this->assertStringNotContainsString('super-secret', (string) $raw);
    }

    public function test_verification_reports_whether_the_answer_came_from_a_simulation(): void
    {
        // Credentials matter even to a simulated adapter: it refuses an
        // unconfigured connection exactly as a real one would, so the
        // verification path is genuinely exercised rather than rubber-stamped.
        $account = $this->account('airbnb', ['credentials' => ['api_key' => 'sim-key']]);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/channels/{$account->getKey()}/verify")
            ->assertOk()
            ->assertJsonPath('data.successful', true)
            // A green tick that does not say this would be a lie by omission.
            ->assertJsonPath('data.is_simulated', true);
    }

    public function test_updating_without_credentials_leaves_them_alone(): void
    {
        $account = $this->account('booking_com', ['credentials' => ['token' => 'keep-me']]);

        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson("/api/v1/channels/{$account->getKey()}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            // An omitted field means "leave them alone", never "clear them" —
            // otherwise editing a display name would silently disconnect the
            // channel.
            ->assertJsonPath('data.has_credentials', true);

        $this->assertSame('keep-me', $account->fresh()->credential('token'));
    }

    public function test_mapping_a_listing_marks_it_as_needing_a_first_push(): void
    {
        $account = $this->account('airbnb');

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/channel-listings', [
                'channel_account_id' => $account->getKey(),
                'listing_id' => $this->listing->getKey(),
                'external_listing_id' => 'abnb-9911',
            ]);

        $response->assertCreated();

        $mapping = ChannelListing::query()->findOrFail($response->json('data.id'));

        // A brand new mapping knows nothing about our calendar, so everything
        // is outstanding — marked rather than pushed, so a slow channel cannot
        // hold up the request.
        $this->assertTrue($mapping->availability_dirty);
        $this->assertTrue($mapping->rates_dirty);
        $this->assertSame($this->property->getKey(), $mapping->property_id);
    }

    public function test_pushing_a_mapping_reports_what_actually_happened(): void
    {
        $mapping = $this->mapping();

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/channel-listings/{$mapping->getKey()}/push", ['what' => 'availability'])
            ->assertOk();

        $this->assertTrue($response->json('data.availability.performed'));
        $this->assertTrue($response->json('data.availability.successful'));

        // A second push has nothing to send, and says so rather than
        // reporting a silent success that invites an operator to keep
        // pressing.
        $again = $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/channel-listings/{$mapping->getKey()}/push", ['what' => 'availability'])
            ->assertOk();

        $this->assertFalse($again->json('data.availability.performed'));
    }

    public function test_unmapping_keeps_the_record(): void
    {
        $mapping = $this->mapping();

        $this->actingAsUser($this->admin, $this->organization)
            ->deleteJson("/api/v1/channel-listings/{$mapping->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        // Bookings taken through it point at it; deleting the row to save
        // space would leave them unable to say where they came from.
        $this->assertDatabaseHas('channel_listings', ['id' => $mapping->getKey()]);
    }

    public function test_disconnecting_keeps_the_account_and_stops_its_mappings(): void
    {
        $mapping = $this->mapping();

        $this->actingAsUser($this->admin, $this->organization)
            ->deleteJson("/api/v1/channels/{$mapping->channel_account_id}")
            ->assertOk()
            ->assertJsonPath('data.status', ChannelAccount::STATUS_DISCONNECTED);

        $this->assertDatabaseHas('channel_accounts', ['id' => $mapping->channel_account_id]);
        $this->assertFalse($mapping->fresh()->is_active);
    }

    public function test_the_health_endpoint_separates_simulated_work_from_real(): void
    {
        $mapping = $this->mapping();

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/channel-listings/{$mapping->getKey()}/push", ['what' => 'availability']);

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->getJson('/api/v1/channel-sync/health')
            ->assertOk();

        $succeeded = $response->json('data.jobs.succeeded');

        $this->assertGreaterThanOrEqual(1, $succeeded['total']);

        // A wall of green ticks from a simulated adapter says nothing about
        // whether real bookings can arrive, so the two are counted apart.
        $this->assertSame($succeeded['total'], $succeeded['simulated']);
    }

    public function test_a_revenue_manager_may_sync_but_not_reconfigure(): void
    {
        $account = $this->account('airbnb');
        $manager = $this->createUser($this->organization, [RoleRegistry::PROPERTY_MANAGER]);

        $this->actingAsUser($manager, $this->organization)
            ->patchJson("/api/v1/channels/{$account->getKey()}", ['commission_basis_points' => 0])
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function account(string $channel, array $attributes = []): ChannelAccount
    {
        return ChannelAccount::query()->create(array_merge([
            'organization_id' => $this->organization->getKey(),
            'channel' => $channel,
            'name' => ucfirst($channel).' account',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'commission_basis_points' => 1500,
        ], $attributes));
    }

    private function mapping(): ChannelListing
    {
        return ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $this->account('airbnb')->getKey(),
            'listing_id' => $this->listing->getKey(),
            'property_id' => $this->property->getKey(),
            'external_listing_id' => 'abnb-1',
        ]);
    }
}
