<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Models\SyncJob;
use App\Domain\Channels\Services\ChannelSynchroniser;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * One Channex property, one message per kind of update.
 *
 * Channex's certification asks for exactly that whenever a test touches more
 * than one room type or rate plan — "Your integration must batch these into 1
 * API call" — and rejects "per-date or per-rate API calls where the test
 * specifies 1 API call". A mapping here is one room type, so pushing mapping
 * by mapping sends one call per room type: a three-room property's full sync
 * was six calls where Channex expects two.
 *
 * The rate limit says the same thing from the other side. It is ten
 * availability and ten restriction calls a minute *per property*, so a
 * property with twelve room types could not even be restated within a minute
 * one room type at a time.
 */
class ChannexBatchPushTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY = '716305c4-561a-4561-a187-7f5b8aeb5920';

    private const OTHER_PROPERTY = '5648db98-e082-49e8-a428-2fd3250b47dd';

    private ChannelSynchroniser $sync;

    private Organization $organization;

    private ChannelAccount $account;

    private ChannelListing $twin;

    private ChannelListing $double;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sync = $this->app->make(ChannelSynchroniser::class);
        $this->organization = $this->createOrganization(['base_currency' => 'USD']);

        $this->account = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'channex',
            'name' => 'Channex — staging',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'credentials' => ['api_key' => 'test-key'],
            'sync_availability' => true,
            'sync_rates' => true,
        ]);

        $this->twin = $this->mapping('Twin Room', 'aaaa0000-0000-4000-8000-000000000001', 'bbbb0000-0000-4000-8000-000000000001');
        $this->double = $this->mapping('Double Room', 'aaaa0000-0000-4000-8000-000000000002', 'bbbb0000-0000-4000-8000-000000000002');

        Http::preventStrayRequests();
    }

    public function test_room_types_in_one_property_share_one_call_per_kind(): void
    {
        $this->fakeChannex();

        $this->twin->markDirty('both');
        $this->double->markDirty('both');

        $result = $this->sync->pushPending($this->account);

        $this->assertSame(4, $result['pushed'], 'Each mapping still counts as pushed.');

        // Two calls for two room types, not four.
        $this->assertSame(1, $this->callsTo('availability'));
        $this->assertSame(1, $this->callsTo('restrictions'));

        $availability = $this->bodyOf('availability');
        $this->assertEqualsCanonicalizing(
            [$this->twin->external_listing_id, $this->double->external_listing_id],
            array_values(array_unique(array_column($availability['values'], 'room_type_id'))),
        );

        $restrictions = $this->bodyOf('restrictions');
        $this->assertEqualsCanonicalizing(
            ['bbbb0000-0000-4000-8000-000000000001', 'bbbb0000-0000-4000-8000-000000000002'],
            array_values(array_unique(array_column($restrictions['values'], 'rate_plan_id'))),
        );

        // Each mapping keeps its own record of the push, quoting the one task
        // the property's message became — which is what certification wants
        // per test, and what a support ticket about either room will cite.
        foreach ([$this->twin, $this->double] as $mapping) {
            $mapping->refresh();
            $this->assertFalse($mapping->availability_dirty);
            $this->assertFalse($mapping->rates_dirty);

            $this->assertSame('task-availability', $this->jobFor($mapping, 'availability')->external_reference);
            $this->assertSame('task-restrictions', $this->jobFor($mapping, 'rates')->external_reference);
        }
    }

    public function test_room_types_in_different_properties_are_not_combined(): void
    {
        $this->fakeChannex();

        $this->double->forceFill(['metadata' => ['channex' => [
            'property_id' => self::OTHER_PROPERTY,
            'rate_plan_id' => 'bbbb0000-0000-4000-8000-000000000002',
            'currency' => 'USD',
        ]]])->save();

        $this->twin->markDirty('availability');
        $this->double->markDirty('availability');

        $this->sync->pushPending($this->account);

        // A Channex message is addressed to one property's queue and budget.
        $this->assertSame(2, $this->callsTo('availability'));
    }

    public function test_one_property_spends_one_slot_however_many_room_types_it_has(): void
    {
        $this->fakeChannex();

        // Room for exactly one call a minute. Mapping by mapping, the second
        // room type would be deferred; as one message, both go.
        config([
            'pms.channels.availability_pushes_per_minute' => 1,
            'pms.channels.rate_pushes_per_minute' => 1,
        ]);

        $this->twin->markDirty('both');
        $this->double->markDirty('both');

        $result = $this->sync->pushPending($this->account);

        $this->assertSame(0, $result['deferred']);
        $this->assertSame(4, $result['pushed']);
    }

    public function test_the_budget_belongs_to_the_channex_property_not_to_ours(): void
    {
        $this->fakeChannex();

        // Two of our properties sold as two room types of one Channex
        // property. Channex counts both against that one property's ten a
        // minute, so a budget kept per property of ours would let twice the
        // real limit through.
        config(['pms.channels.availability_pushes_per_minute' => 1]);

        $first = $this->sync->pushAvailability($this->twin->fresh(['account', 'listing.property']));
        $second = $this->sync->pushAvailability($this->double->fresh(['account', 'listing.property']));

        $this->assertTrue($first?->successful);
        $this->assertSame(ChannelSynchroniser::ERROR_RATE_LIMITED, $second?->errorCode);
        $this->assertSame(1, $this->callsTo('availability'));
    }

    public function test_an_incomplete_mapping_is_refused_without_holding_back_the_rest(): void
    {
        $this->fakeChannex();

        $this->double->forceFill(['metadata' => ['channex' => ['property_id' => self::PROPERTY]]])->save();

        $this->twin->markDirty('rates');
        $this->double->markDirty('rates');

        $this->sync->pushPending($this->account);

        $this->assertSame(1, $this->callsTo('restrictions'));
        $this->assertSame(
            ['bbbb0000-0000-4000-8000-000000000001'],
            array_values(array_unique(array_column($this->bodyOf('restrictions')['values'], 'rate_plan_id'))),
        );

        $this->assertFalse($this->twin->fresh()->rates_dirty);

        $double = $this->double->fresh();
        $this->assertTrue($double->rates_dirty);
        $this->assertStringContainsString('rate plan', (string) $double->last_error);
    }

    public function test_a_rejection_naming_one_rate_plan_fails_only_that_mapping(): void
    {
        // Channex applies what it can and lists what it refused, by rate plan.
        // Failing the whole property for one room type's bad night would mark
        // every other mapping as broken and, after a few runs, stop sending
        // them at all.
        Http::fake([
            '*/restrictions' => Http::response([
                'data' => [['id' => 'task-partial', 'type' => 'task']],
                'meta' => ['message' => 'Success', 'warnings' => [[
                    'date' => now()->addDay()->toDateString(),
                    'property_id' => self::PROPERTY,
                    'rate_plan_id' => 'bbbb0000-0000-4000-8000-000000000002',
                    'rate' => '-2',
                    'warning' => ['rate' => ['must be greater than 0']],
                ]]],
            ]),
        ]);

        $this->twin->markDirty('rates');
        $this->double->markDirty('rates');

        $this->sync->pushPending($this->account);

        $twin = $this->twin->fresh();
        $this->assertFalse($twin->rates_dirty);
        $this->assertSame('task-partial', $this->jobFor($twin, 'rates')->external_reference);

        $double = $this->double->fresh();
        $this->assertTrue($double->rates_dirty);
        $this->assertStringContainsString('must be greater than 0', (string) $double->last_error);
    }

    public function test_a_rejection_that_names_nobody_fails_everybody(): void
    {
        Http::fake([
            '*/restrictions' => Http::response([
                'data' => [],
                'meta' => ['message' => 'Success', 'warnings' => ['the message could not be processed']],
            ]),
        ]);

        $this->twin->markDirty('rates');
        $this->double->markDirty('rates');

        $this->sync->pushPending($this->account);

        // Nothing says which room type it was about, so none of them is
        // assumed to have landed.
        $this->assertTrue($this->twin->fresh()->rates_dirty);
        $this->assertTrue($this->double->fresh()->rates_dirty);
    }

    public function test_a_full_sync_restates_the_property_in_two_calls(): void
    {
        $this->fakeChannex();

        $this->artisan('channels:full-sync', ['--account' => $this->account->getKey(), '--pause' => 0])
            ->expectsOutputToContain('task-availability')
            ->assertSuccessful();

        // Certification test 1: "1 x 500 days for Availability (All Rooms),
        // 1 x 500 days Rates & restrictions (All Rates)".
        $this->assertSame(1, $this->callsTo('availability'));
        $this->assertSame(1, $this->callsTo('restrictions'));

        $values = $this->bodyOf('availability')['values'];
        $this->assertCount(2, array_unique(array_column($values, 'room_type_id')));

        // Five hundred nights, starting tonight.
        $last = max(array_map(fn (array $v): string => $v['date_to'] ?? $v['date'], $values));
        $this->assertSame(now()->addDays(499)->toDateString(), $last);
    }

    public function test_one_night_priced_on_the_calendar_reaches_channex(): void
    {
        $this->fakeChannex();

        // Certification test 2, done the way they will watch it done: one
        // night's price changed on the calendar, in the product.
        $night = now()->addDays(30)->toDateString();
        $admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        $this->actingAsUser($admin, $this->organization)
            ->patchJson("/api/v1/calendar/listings/{$this->twin->listing_id}/days", [
                'from' => $night,
                'to' => $night,
                'rate_override' => 33300,
            ])
            ->assertOk();

        $this->sync->pushPending($this->account);

        // The calendar's `to` is the last night changed; the engines' end is
        // exclusive. Read as an end, a one-night edit was a window of no
        // nights: "No dates were supplied", and the price never left.
        $entry = collect($this->bodyOf('restrictions')['values'] ?? [])
            ->first(fn (array $v): bool => ($v['date'] ?? $v['date_from'] ?? null) <= $night
                && ($v['date'] ?? $v['date_to'] ?? null) >= $night
                && array_key_exists('rate', $v));

        $this->assertSame(33300, $entry['rate'] ?? null);
        $this->assertFalse($this->twin->fresh()->rates_dirty);
    }

    public function test_the_last_night_of_a_calendar_edit_is_sent(): void
    {
        $this->fakeChannex();

        $first = now()->addDays(40)->toDateString();
        $last = now()->addDays(42)->toDateString();
        $admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        $this->actingAsUser($admin, $this->organization)
            ->patchJson("/api/v1/calendar/listings/{$this->twin->listing_id}/days", [
                'from' => $first,
                'to' => $last,
                'is_blocked' => true,
            ])
            ->assertOk();

        $this->sync->pushPending($this->account);

        $closed = collect($this->bodyOf('availability')['values'] ?? [])
            ->filter(fn (array $v): bool => ($v['availability'] ?? null) === 0)
            ->flatMap(fn (array $v): array => isset($v['date'])
                ? [$v['date']]
                : collect(CarbonPeriod::create($v['date_from'], $v['date_to']))->map->toDateString()->all())
            ->values()
            ->all();

        $this->assertContains($last, $closed, 'The last night closed must be closed on the channel too.');
    }

    public function test_a_very_large_property_is_split_rather_than_refused(): void
    {
        $this->fakeChannex();

        // Channex takes up to 10mb a call. A message bigger than that is not
        // queued at all, so a property too large for one call is sent in as
        // few as fit, whole room types at a time.
        config(['pms.channels.channex.max_values_per_call' => 1]);

        $this->twin->markDirty('availability');
        $this->double->markDirty('availability');

        $this->sync->pushPending($this->account);

        $this->assertSame(2, $this->callsTo('availability'));
        $this->assertFalse($this->twin->fresh()->availability_dirty);
        $this->assertFalse($this->double->fresh()->availability_dirty);
    }

    // ------------------------------------------------------------------

    private function mapping(string $name, string $roomType, string $ratePlan): ChannelListing
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

        return ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $this->account->getKey(),
            'listing_id' => $listing->getKey(),
            'property_id' => $property->getKey(),
            'external_listing_id' => $roomType,
            'external_name' => $name,
            'is_active' => true,
            'metadata' => ['channex' => [
                'property_id' => self::PROPERTY,
                'rate_plan_id' => $ratePlan,
                'currency' => 'USD',
            ]],
        ]);
    }

    private function fakeChannex(): void
    {
        Http::fake([
            '*/availability' => Http::response($this->task('task-availability')),
            '*/restrictions' => Http::response($this->task('task-restrictions')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function task(string $id): array
    {
        return ['data' => [['id' => $id, 'type' => 'task']], 'meta' => ['message' => 'Success', 'warnings' => []]];
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

    private function jobFor(ChannelListing $mapping, string $kind): SyncJob
    {
        return SyncJob::query()
            ->where('channel_listing_id', $mapping->getKey())
            ->where('kind', $kind)
            ->latest('started_at')
            ->firstOrFail();
    }
}
