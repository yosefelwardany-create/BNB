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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Certification test 1 from the product, and the task ids to quote for it.
 *
 * Channex's first test is a full sync — 500 days of availability, prices and
 * restrictions, as two calls — and the form asks for the task id each test
 * produced. Both used to need a server shell: the full sync was a console
 * command, and the ids were stored but on no screen.
 */
class ChannexFullSyncTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY = '716305c4-561a-4561-a187-7f5b8aeb5920';

    private Organization $organization;

    private ChannelAccount $account;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'USD']);
        $this->admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        $this->account = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'channex',
            'name' => 'Channex — staging',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'credentials' => ['api_key' => 'test-key'],
            'sync_availability' => true,
            'sync_rates' => true,
        ]);

        $this->mapping('Twin Room', 'aaaa0000-0000-4000-8000-000000000001', 'bbbb0000-0000-4000-8000-000000000001');
        $this->mapping('Double Room', 'aaaa0000-0000-4000-8000-000000000002', 'bbbb0000-0000-4000-8000-000000000002');

        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            '*/availability' => Http::response(['data' => [['id' => 'task-availability', 'type' => 'task']], 'meta' => ['message' => 'Success']]),
            '*/restrictions' => Http::response(['data' => [['id' => 'task-restrictions', 'type' => 'task']], 'meta' => ['message' => 'Success']]),
        ]);
    }

    public function test_the_button_restates_500_days_in_two_calls(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/channels/{$this->account->getKey()}/full-sync")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued');

        // Both room types of the property together: one availability call and
        // one price-and-restrictions call, as test 1 asks.
        $this->assertSame(1, $this->callsTo('availability'));
        $this->assertSame(1, $this->callsTo('restrictions'));

        $dates = array_column($this->bodyOf('availability')['values'], 'date_to');
        $this->assertContains(now()->addDays(499)->toDateString(), $dates, 'Five hundred nights, starting tonight.');
    }

    public function test_the_task_ids_are_on_the_sync_log_by_room_type(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/channels/{$this->account->getKey()}/full-sync")
            ->assertAccepted();

        $log = $this->actingAsUser($this->admin, $this->organization)
            ->getJson('/api/v1/channel-sync/jobs?with_reference=1&direction=push&channel_account_id='.$this->account->getKey())
            ->assertOk();

        $rows = collect($log->json('data'));

        $this->assertCount(4, $rows, 'Two room types, availability and rates each.');
        $this->assertEqualsCanonicalizing(['task-availability', 'task-restrictions'], $rows->pluck('external_reference')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['Twin Room', 'Double Room'], $rows->pluck('listing_name')->unique()->values()->all());
    }

    public function test_a_connection_that_pushes_nothing_is_told_why(): void
    {
        $this->account->forceFill(['sync_availability' => false, 'sync_rates' => false])->save();

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/channels/{$this->account->getKey()}/full-sync")
            ->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Switch on pushing availability or rates in this connection\'s settings first.']);

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------

    private function mapping(string $name, string $roomType, string $ratePlan): void
    {
        $property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'USD',
            'base_rate' => 10000,
            'cleaning_fee' => 0,
            'max_occupancy' => 2,
        ]);

        $listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
            'currency' => 'USD',
            'minimum_nights' => 1,
        ]);

        ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $this->account->getKey(),
            'listing_id' => $listing->getKey(),
            'property_id' => $property->getKey(),
            'external_listing_id' => $roomType,
            'external_name' => $name,
            'is_active' => true,
            'metadata' => ['channex' => ['property_id' => self::PROPERTY, 'rate_plan_id' => $ratePlan, 'currency' => 'USD']],
        ]);
    }

    private function callsTo(string $endpoint): int
    {
        return Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/'.$endpoint))->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function bodyOf(string $endpoint): array
    {
        $call = Http::recorded(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/'.$endpoint))->first();

        return $call === null ? [] : (array) $call[0]->data();
    }
}
