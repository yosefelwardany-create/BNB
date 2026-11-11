<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelSynchroniser;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Channex is sent what changed: the nights an edit touched, with the rules it
 * set, and nothing else.
 *
 * Channex's certification checks each message against the action that caused
 * it. A price set for one night is one value carrying a rate. A minimum stay
 * is a value carrying a minimum stay. Rules saved together over ten nights are
 * one value over those ten nights carrying exactly those rules, a rule set to
 * what it already was included. Restating the rest of the window, or the
 * other rules for the same nights, failed every test from 2 to 7.
 *
 * A full sync is the other way round: every value states every restriction.
 */
class ChannexChangesOnlyTest extends TestCase
{
    use RefreshDatabase;

    private const PROPERTY = '716305c4-561a-4561-a187-7f5b8aeb5920';

    private const TWIN_RATE = 'bbbb0000-0000-4000-8000-000000000001';

    private const DOUBLE_RATE = 'bbbb0000-0000-4000-8000-000000000002';

    private const TWIN_ROOM = 'aaaa0000-0000-4000-8000-000000000001';

    private const DOUBLE_ROOM = 'aaaa0000-0000-4000-8000-000000000002';

    private const ALL_RESTRICTIONS = ['rate', 'min_stay_arrival', 'max_stay', 'closed_to_arrival', 'closed_to_departure', 'stop_sell'];

    private ChannelSynchroniser $sync;

    private Organization $organization;

    private ChannelAccount $account;

    private ChannelListing $twin;

    private ChannelListing $double;

    private User $admin;

    private CarbonImmutable $night;

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

        $this->twin = $this->mapping('Twin Room', self::TWIN_ROOM, self::TWIN_RATE);
        $this->double = $this->mapping('Double Room', self::DOUBLE_ROOM, self::DOUBLE_RATE);
        $this->admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);
        $this->night = CarbonImmutable::today()->addDays(40);

        Http::preventStrayRequests();
        $this->onRequest = fn (Request $request) => Http::response($this->task(
            str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/availability') ? 'task-availability' : 'task-restrictions',
        ));
        Http::fake(fn (Request $request) => ($this->onRequest)($request));

        // Channex has been told everything once; what follows are changes.
        $this->sync->fullSyncMany([$this->twin, $this->double]);
        $this->fullSyncCalls = Http::recorded()->count();
    }

    public function test_a_full_sync_states_every_restriction_on_every_value(): void
    {
        $values = $this->allValues('restrictions', 0);

        $this->assertNotEmpty($values);

        foreach ($values as $value) {
            foreach (self::ALL_RESTRICTIONS as $field) {
                $this->assertArrayHasKey($field, $value, $field.' must be on every value of a full sync.');
            }
        }

        // No maximum stay is a maximum of none, which Channex writes as 0.
        $this->assertSame([0], array_values(array_unique(array_column($values, 'max_stay'))));
    }

    public function test_one_price_for_one_night_is_one_value_with_a_rate(): void
    {
        $this->edit($this->twin, $this->night, $this->night, ['rate_override' => 33300]);
        $this->sync->pushPending($this->account);

        $this->assertSame([
            ['date' => $this->night->toDateString(), 'rate' => 33300],
        ], $this->sentFor('restrictions', self::TWIN_RATE));
        $this->assertSame(0, $this->callsTo('availability'));
    }

    public function test_prices_for_two_rooms_are_one_call_with_one_value_each(): void
    {
        $this->edit($this->twin, $this->night, $this->night, ['rate_override' => 33300]);
        $this->edit($this->double, $this->night->addDays(4), $this->night->addDays(4), ['rate_override' => 44400]);
        $this->sync->pushPending($this->account);

        $this->assertSame(1, $this->callsTo('restrictions'));
        $this->assertSame([['date' => $this->night->toDateString(), 'rate' => 33300]], $this->sentFor('restrictions', self::TWIN_RATE));
        $this->assertSame([['date' => $this->night->addDays(4)->toDateString(), 'rate' => 44400]], $this->sentFor('restrictions', self::DOUBLE_RATE));
    }

    public function test_a_price_for_a_range_is_one_value_over_the_range(): void
    {
        $this->edit($this->twin, $this->night, $this->night->addDays(9), ['rate_override' => 24100]);
        $this->sync->pushPending($this->account);

        $this->assertSame([[
            'date_from' => $this->night->toDateString(),
            'date_to' => $this->night->addDays(9)->toDateString(),
            'rate' => 24100,
        ]], $this->sentFor('restrictions', self::TWIN_RATE));
    }

    public function test_a_minimum_stay_is_sent_alone(): void
    {
        $this->edit($this->twin, $this->night, $this->night, ['minimum_nights' => 3]);
        $this->sync->pushPending($this->account);

        $this->assertSame([['date' => $this->night->toDateString(), 'min_stay_arrival' => 3]], $this->sentFor('restrictions', self::TWIN_RATE));
        $this->assertSame(0, $this->callsTo('availability'));
    }

    public function test_a_stop_sell_is_one_restrictions_call_and_no_availability(): void
    {
        $this->edit($this->twin, $this->night, $this->night, ['is_blocked' => true]);
        $this->sync->pushPending($this->account);

        $this->assertSame([['date' => $this->night->toDateString(), 'stop_sell' => true]], $this->sentFor('restrictions', self::TWIN_RATE));
        $this->assertSame(0, $this->callsTo('availability'));
    }

    public function test_a_night_closed_by_hand_keeps_its_count_and_reopens_by_lifting_the_stop_sell(): void
    {
        $this->edit($this->twin, $this->night, $this->night, ['is_blocked' => true]);
        $this->sync->pushPending($this->account);

        // Restated while it is closed: still one unit to sell, closed to sale
        // by the stop sell alone. Sent as 0, nothing would ever put it back,
        // because lifting a stop sell sends no availability.
        $this->sync->fullSyncMany([$this->twin]);
        $availability = $this->calls('availability');
        $this->assertSame(1, $this->countOn(end($availability), self::TWIN_ROOM, $this->night->toDateString()));

        $this->edit($this->twin, $this->night, $this->night, ['is_blocked' => false]);
        $this->sync->pushPending($this->account);

        $this->assertSame([['date' => $this->night->toDateString(), 'stop_sell' => false]], $this->sentFor('restrictions', self::TWIN_RATE, last: true));
        $this->assertCount(1, $this->calls('availability'), 'Only the full sync sent availability.');
    }

    public function test_rules_saved_together_are_one_value_with_exactly_those_rules(): void
    {
        // Certification test 7's first row. Closed to departure and the
        // minimum stay are what they already were, and are still sent: they
        // are what was set.
        $this->edit($this->twin, $this->night, $this->night->addDays(9), [
            'closed_to_arrival' => true,
            'closed_to_departure' => false,
            'maximum_nights' => 4,
            'minimum_nights' => 1,
        ]);
        $this->sync->pushPending($this->account);

        $this->assertSame([[
            'date_from' => $this->night->toDateString(),
            'date_to' => $this->night->addDays(9)->toDateString(),
            'min_stay_arrival' => 1,
            'max_stay' => 4,
            'closed_to_arrival' => true,
            'closed_to_departure' => false,
        ]], $this->sentFor('restrictions', self::TWIN_RATE));
    }

    public function test_two_edits_to_one_room_send_only_their_own_nights(): void
    {
        $this->edit($this->twin, $this->night, $this->night, ['rate_override' => 33300]);
        $this->edit($this->twin, $this->night->addDays(5), $this->night->addDays(5), ['minimum_nights' => 3]);
        $this->sync->pushPending($this->account);

        $this->assertSame([
            ['date' => $this->night->toDateString(), 'rate' => 33300],
            ['date' => $this->night->addDays(5)->toDateString(), 'min_stay_arrival' => 3],
        ], $this->sentFor('restrictions', self::TWIN_RATE));
    }

    public function test_a_block_sends_availability_for_its_nights_and_not_the_morning_it_ends(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/calendar/blocks', [
                'property_id' => $this->twin->property_id,
                'kind' => 'owner_stay',
                'start_date' => $this->night->toDateString(),
                'end_date' => $this->night->addDays(7)->toDateString(),
            ])
            ->assertCreated();
        $this->sync->pushPending($this->account);

        $this->assertSame([[
            'date_from' => $this->night->toDateString(),
            'date_to' => $this->night->addDays(6)->toDateString(),
            'availability' => 0,
        ]], $this->sentFor('availability', self::TWIN_ROOM));
        $this->assertSame(0, $this->callsTo('restrictions'));
    }

    public function test_a_booking_sends_availability_for_its_nights_and_not_check_out(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/reservations', [
                'listing_id' => $this->twin->listing_id,
                'check_in' => $this->night->toDateString(),
                'check_out' => $this->night->addDays(2)->toDateString(),
                'guest' => ['first_name' => 'Ada', 'email' => 'ada@example.test'],
            ])
            ->assertCreated();
        $this->sync->pushPending($this->account);

        $this->assertSame([[
            'date_from' => $this->night->toDateString(),
            'date_to' => $this->night->addDays(1)->toDateString(),
            'availability' => 0,
        ]], $this->sentFor('availability', self::TWIN_ROOM));
    }

    public function test_a_change_made_while_a_push_is_on_its_way_is_still_sent(): void
    {
        $this->edit($this->twin, $this->night, $this->night, ['rate_override' => 33300]);

        // While Channex is answering, someone changes another night.
        $answer = $this->onRequest;
        $interrupted = false;
        $this->onRequest = function (Request $request) use ($answer, &$interrupted) {
            if (! $interrupted) {
                $interrupted = true;
                $this->edit($this->twin, $this->night->addDays(3), $this->night->addDays(3), ['rate_override' => 22200]);
            }

            return $answer($request);
        };

        $this->sync->pushPending($this->account);
        $this->assertTrue($this->twin->fresh()->rates_dirty, 'The later change has not been sent yet.');

        $this->sync->pushPending($this->account);
        $this->assertSame([['date' => $this->night->addDays(3)->toDateString(), 'rate' => 22200]], $this->sentFor('restrictions', self::TWIN_RATE, last: true));
    }

    /** @var \Closure(Request): mixed */
    private \Closure $onRequest;

    private int $fullSyncCalls = 0;

    /**
     * @param  array<string, mixed>  $changes
     */
    private function edit(ChannelListing $mapping, CarbonImmutable $first, CarbonImmutable $last, array $changes): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson("/api/v1/calendar/listings/{$mapping->listing_id}/days", [
                'from' => $first->toDateString(),
                'to' => $last->toDateString(),
            ] + $changes)
            ->assertOk();
    }

    /**
     * What one call to an endpoint said about one rate plan or room type,
     * without the addressing every value carries.
     *
     * @return list<array<string, mixed>>
     */
    private function sentFor(string $endpoint, string $address, bool $last = false): array
    {
        $key = $endpoint === 'availability' ? 'room_type_id' : 'rate_plan_id';
        $calls = $this->calls($endpoint);
        $body = $last ? end($calls) : ($calls[0] ?? []);

        return array_values(array_map(
            fn (array $value): array => array_diff_key($value, ['property_id' => true, 'rate_plan_id' => true, 'room_type_id' => true]),
            array_filter($body['values'] ?? [], fn (array $value): bool => ($value[$key] ?? null) === $address),
        ));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function countOn(array $body, string $roomType, string $night): ?int
    {
        foreach ($body['values'] ?? [] as $value) {
            if (($value['room_type_id'] ?? null) === $roomType
                && ($value['date'] ?? $value['date_from']) <= $night
                && ($value['date'] ?? $value['date_to']) >= $night) {
                return $value['availability'];
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allValues(string $endpoint, int $call): array
    {
        return $this->calls($endpoint, includeFullSync: true)[$call]['values'] ?? [];
    }

    /**
     * Every body sent to an endpoint after the full sync, or with it.
     *
     * @return list<array<string, mixed>>
     */
    private function calls(string $endpoint, bool $includeFullSync = false): array
    {
        return Http::recorded()
            ->slice($includeFullSync ? 0 : $this->fullSyncCalls)
            ->filter(fn (array $pair): bool => $pair[0]->method() === 'POST'
                && str_ends_with(parse_url($pair[0]->url(), PHP_URL_PATH) ?: '', '/'.$endpoint))
            ->map(fn (array $pair): array => (array) $pair[0]->data())
            ->values()
            ->all();
    }

    private function callsTo(string $endpoint): int
    {
        return count($this->calls($endpoint));
    }

    /**
     * @return array<string, mixed>
     */
    private function task(string $id): array
    {
        return ['data' => [['id' => $id, 'type' => 'task']], 'meta' => ['message' => 'Success', 'warnings' => []]];
    }

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
}
