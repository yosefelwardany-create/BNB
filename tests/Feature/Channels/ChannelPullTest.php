<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelPuller;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Connecting a channel has to actually bring the data in.
 *
 * This is the gap these tests exist to close. Every piece already existed — an
 * adapter that could read listings, an importer that could map them, a
 * reservation importer, a message importer — and nothing called any of them on a
 * schedule. The scheduler named `channels:poll`, which was never written. So
 * connecting Hostex produced a row in a table and no data, which is the worst
 * possible version of an integration: it looks connected.
 *
 * Two things are being asserted. That a pull brings listings, bookings and
 * threads in, in that order, because a booking names a listing and the mapping
 * is what says which of ours that is. And that one failing stage does not take
 * the others down with it: a channel that cannot serve threads is not a reason
 * to throw away the bookings it just served.
 */
class ChannelPullTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    public function test_manual_pull_returns_immediately_and_the_worker_runs_the_queued_full_import(): void
    {
        $this->property('Queued fixture');
        $account = $this->account();
        $account->forceFill(['last_pull_attempted_at' => now(), 'last_synced_at' => now()])->save();
        $this->fakeHostex(['properties' => [['id' => 'hx-1', 'title' => 'Queued fixture']]]);
        $this->postJson('/api/v1/channels/'.$account->id.'/pull', ['full' => true])
            ->assertStatus(202)->assertJsonPath('data.status', 'queued');
        Http::assertNothingSent();
        $this->postJson('/api/v1/channels/'.$account->id.'/pull', ['full' => false])
            ->assertStatus(202)->assertJsonPath('data.full', true);
        $this->artisan('channels:watch', ['--once' => true])->assertSuccessful();
        $result = $account->fresh()->last_pull_result;
        $this->assertSame('completed', $result['status']);
        $this->assertSame('manual', $result['trigger']);
        $this->assertNull($result['since']);
    }

    public function test_requesting_a_manual_pull_does_not_replace_an_active_import(): void
    {
        $this->property('Queued fixture');
        $account = $this->account();
        DB::table('cache_locks')->insert([
            'key' => 'channel-pull:'.$account->organization_id.':'.$account->id,
            'owner' => 'active-worker', 'expiration' => now()->addMinutes(10)->timestamp,
        ]);
        $this->postJson('/api/v1/channels/'.$account->id.'/pull')->assertStatus(202)->assertJsonPath('data.status', 'running');
        $this->assertNull($account->fresh()->last_pull_result);
    }

    public function test_background_import_runs_when_due_and_does_not_repeat_a_recent_manual_or_automatic_pull(): void
    {
        $this->property('Background fixture');
        $account = $this->account();
        $staleAccount = $account->fresh();
        $this->fakeHostex(['properties' => [['id' => 'hx-1', 'title' => 'Background fixture']]]);
        $this->artisan('channels:watch', ['--once' => true])->assertSuccessful();
        $this->assertSame('automatic', $account->fresh()->last_pull_result['trigger']);
        $this->assertSame('completed', $account->fresh()->last_pull_result['status']);
        $firstAttempt = $account->fresh()->last_pull_attempted_at;
        $requests = Http::recorded()->count();
        $this->assertSame('not_due', app(ChannelPuller::class)->pull($staleAccount, automatic: true)['status']);
        $this->artisan('channels:watch', ['--once' => true])->assertSuccessful();
        $this->assertSame($requests, Http::recorded()->count());
        $this->travel(6)->minutes();
        $this->artisan('channels:watch', ['--once' => true])->assertSuccessful();
        $this->assertTrue($account->fresh()->last_pull_attempted_at->gt($firstAttempt));
        app(ChannelPuller::class)->pull($account);
        $requests = Http::recorded()->count();
        $this->artisan('channels:watch', ['--once' => true])->assertSuccessful();
        $this->assertSame($requests, Http::recorded()->count());
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' && ! str_contains($request->url(), '/listings/calendar'));
    }

    public function test_automatic_imports_refresh_each_stage_only_as_often_as_it_changes(): void
    {
        // Mid-morning, so no step below crosses midnight and moves the
        // imported calendar window.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        $property = $this->property('Cadence fixture');
        $account = $this->account();
        $this->map($account, $property);
        $this->fakeHostex(['properties' => [['id' => 'hx-1', 'title' => 'Cadence fixture']]]);

        // The first automatic run does everything.
        $this->artisan('channels:watch', ['--once' => true])->assertSuccessful();
        $first = $account->fresh()->last_pull_result;
        $this->assertArrayNotHasKey('skipped', $first['properties']);

        // Five minutes on: bookings again, property details not.
        $this->travel(6)->minutes();
        $this->artisan('channels:watch', ['--once' => true])->assertSuccessful();
        $second = $account->fresh()->last_pull_result;
        $this->assertSame('completed', $second['status']);
        $this->assertArrayNotHasKey('skipped', $second['reservations']);
        $this->assertArrayHasKey('skipped', $second['properties']);
        $this->assertArrayHasKey('skipped', $second['availability']);
        $this->assertArrayHasKey('skipped', $second['transactions']);

        // Six hours on: property details are due again.
        $this->travel(6)->hours();
        $this->artisan('channels:watch', ['--once' => true])->assertSuccessful();
        $this->assertArrayNotHasKey('skipped', $account->fresh()->last_pull_result['properties']);

        // A pull somebody asks for always does everything.
        $this->travel(6)->minutes();
        $manual = app(ChannelPuller::class)->pull($account->fresh());
        $this->assertArrayNotHasKey('skipped', $manual['properties']);
        $this->assertArrayNotHasKey('skipped', $manual['availability']);
    }

    public function test_an_unchanged_import_does_not_rewrite_or_re_audit_the_property(): void
    {
        // Mid-morning, so no step below crosses midnight and moves the
        // imported calendar window.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
        $property = $this->property('Quiet fixture');
        $account = $this->account();
        $this->map($account, $property);
        $this->fakeHostex(['properties' => [['id' => 'hx-1', 'title' => 'Quiet fixture']]]);

        // The first import fills the property in, and the second may settle a
        // field the first one introduced. From then on the same data must
        // write nothing.
        app(ChannelPuller::class)->pull($account->fresh());
        $this->travel(1)->hours();
        app(ChannelPuller::class)->pull($account->fresh());
        $propertyAudits = AuditLog::query()->where('action', 'property.updated')->count();

        $this->travel(1)->hours();
        app(ChannelPuller::class)->pull($account->fresh());

        $new = AuditLog::query()->where('action', 'property.updated')->orderBy('created_at')->get()->slice($propertyAudits);

        $this->assertSame(
            $propertyAudits,
            AuditLog::query()->where('action', 'property.updated')->count(),
            'An unchanged import rewrote the property: '.json_encode($new->map(fn ($row) => array_keys($row->new_values ?? []))->values()),
        );
    }

    public function test_import_bookkeeping_is_not_audited_but_a_real_change_is(): void
    {
        $this->property('Audit fixture');
        $account = $this->account();
        $before = AuditLog::query()->where('action', 'channel_account.updated')->count();

        $account->forceFill([
            'last_pull_result' => ['status' => 'completed'],
            'last_pull_attempted_at' => now(),
            'last_pull_succeeded_at' => now(),
            'last_synced_at' => now(),
        ])->save();

        $this->assertSame($before, AuditLog::query()->where('action', 'channel_account.updated')->count());

        // A change somebody made is still recorded, without the bookkeeping
        // saved alongside it.
        $account->forceFill(['name' => 'Hostex (renamed)', 'last_pull_attempted_at' => now()->addMinute()])->save();

        $row = AuditLog::query()->where('action', 'channel_account.updated')->latest('created_at')->firstOrFail();
        $this->assertSame($before + 1, AuditLog::query()->where('action', 'channel_account.updated')->count());
        $this->assertSame(['name'], array_keys($row->new_values));
    }

    public function test_background_import_skips_disconnected_accounts_and_accounts_with_an_active_pull(): void
    {
        $this->property('Background fixture');
        $account = $this->account(['status' => 'disconnected']);
        Http::fake();
        $this->artisan('channels:watch', ['--once' => true])->assertSuccessful();
        Http::assertNothingSent();
        $account->forceFill(['status' => 'connected'])->save();
        DB::table('cache_locks')->insert([
            'key' => 'channel-pull:'.$account->organization_id.':'.$account->id,
            'owner' => 'another-process', 'expiration' => now()->addMinutes(10)->timestamp,
        ]);
        $this->artisan('channels:watch', ['--once' => true])->assertSuccessful();
        Http::assertNothingSent();
        $this->assertNull($account->fresh()->last_pull_attempted_at);
    }

    public function test_a_pull_updates_an_explicit_mapping_then_imports_its_bookings(): void
    {
        $property = $this->property('Yellow Room');
        $account = $this->account();
        $this->map($account, $property);

        $this->fakeHostex([
            'properties' => [[
                'id' => 'hx-1',
                // Matched on the name, and only when exactly one property
                // matches: a guess here attaches somebody's bookings to the
                // wrong flat.
                'title' => 'Yellow Room',
            ]],
            'reservations' => [[
                'reservation_code' => 'HX-ABC-1',
                'property_id' => 'hx-1',
                'check_in_date' => '2026-07-01',
                'check_out_date' => '2026-07-04',
                'status' => 'accepted',
                'guest_name' => 'Marta Silva', 'guest_email' => 'marta@example.test',
                'rates' => ['rate' => ['amount' => '450.00', 'currency' => 'EUR']],
            ]],
        ]);

        $outcome = $this->app->make(ChannelPuller::class)->pull($account, full: true);

        $mapping = ChannelListing::query()->sole();
        $this->assertSame('hx-1', $mapping->external_listing_id);
        $this->assertSame(
            $property->getKey(),
            $mapping->property_id,
            'The explicit source mapping remains attached to the same property.',
        );

        $reservation = Reservation::query()->sole();
        $this->assertSame('HX-ABC-1', $reservation->external_reservation_id);
        $this->assertSame($property->getKey(), $reservation->property_id);

        // And the account now knows where to resume from, set to when the pull
        // started rather than when it finished: anything that changed while it
        // was running must be picked up next time rather than skipped.
        $this->assertNotNull($account->fresh()->last_synced_at);
        $this->assertArrayHasKey('at', $outcome);
    }

    public function test_a_listing_matching_two_properties_is_recorded_unmapped_rather_than_guessed(): void
    {
        // Both in the *same* company: two properties of the same name in one
        // portfolio is the ambiguity. Across companies there is no ambiguity,
        // because neither can see the other.
        $this->property('Yellow Room');
        $this->alsoProperty('Yellow Room');

        $account = $this->account();

        $this->fakeHostex(['properties' => [['id' => 'hx-1', 'title' => 'Yellow Room']]]);

        $this->app->make(ChannelPuller::class)->pull($account, full: true);

        $mapping = ChannelListing::query()->sole();

        /*
         * Discovered and left for a person.
         *
         * The alternative is picking one, and a wrong mapping sends a guest's
         * messages and a booking's money to the wrong property — a mistake
         * nobody notices until the owner statement is wrong. An unmapped row is
         * a question on a screen, which is what this situation actually is.
         */
        $this->assertNull($mapping->property_id);
        $this->assertNull($mapping->listing_id);
    }

    public function test_a_failing_stage_does_not_throw_away_what_the_others_brought_in(): void
    {
        $property = $this->property('Yellow Room');
        $account = $this->account(['sync_messages' => true]);
        $this->map($account, $property);

        $this->fakeHostex(
            [
                'properties' => [['id' => 'hx-1', 'title' => 'Yellow Room']],
                'reservations' => [[
                    'reservation_code' => 'HX-ABC-1',
                    'property_id' => 'hx-1',
                    'check_in_date' => '2026-07-01',
                    'check_out_date' => '2026-07-04',
                    'status' => 'accepted',
                    'guest_name' => 'Marta Silva',
                    'rates' => ['rate' => ['amount' => '450.00', 'currency' => 'EUR']],
                ]],
            ],
            // Hostex answers errors with HTTP 200 and a code in the body, rate
            // limits included — so a status-only check would read this as an
            // empty inbox and record that there were no messages.
            conversationsError: ['error_code' => 429, 'error_msg' => 'Too many requests.'],
        );

        $outcome = $this->app->make(ChannelPuller::class)->pull($account, full: true);

        $this->assertSame(1, ChannelListing::query()->count());
        $this->assertSame(1, Reservation::query()->count());

        // The threads stage says what went wrong and whether it is worth trying
        // again — the difference between a rate limit and a revoked token, which
        // is the first thing somebody debugging this needs.
        $this->assertArrayHasKey('failed', $outcome['messages']);
        $this->assertTrue($outcome['messages']['retryable']);
        $this->assertSame($property->getKey(), ChannelListing::query()->sole()->property_id);
    }

    public function test_messages_are_skipped_with_a_reason_rather_than_counted_as_none(): void
    {
        $this->property('Yellow Room');
        // Off by default, because importing a channel's inbox into this one
        // changes which screen an operator works from.
        $account = $this->account(['sync_messages' => false]);

        $this->fakeHostex(['properties' => []]);

        $outcome = $this->app->make(ChannelPuller::class)->pull($account, full: true);

        // "Not set to" and "there were none" look the same in a count and are
        // nothing alike on a screen that says the inbox is empty.
        $this->assertArrayHasKey('skipped', $outcome['messages']);
    }

    public function test_a_new_connection_does_not_push_its_empty_calendar(): void
    {
        $this->property('Yellow Room');

        // Created with no flags set at all — whatever a fresh connection gets.
        $account = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'hostex',
            'name' => 'Hostex',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'credentials' => ['access_token' => 'token-for-tests'],
        ]);

        /*
         * The expensive default, corrected.
         *
         * A channel manager becomes the source of truth for availability the
         * moment it is linked, and a new connection's calendar is empty. Pushing
         * it publishes "everything is available" over a calendar where that is
         * false: nights that are sold come back open, they sell again, and the
         * first anybody knows is two parties at one door. Hostex documents the
         * same behaviour for its own link step, so this platform pushing an empty
         * calendar would be passed straight through to Airbnb.
         *
         * Not pushing is recoverable — somebody notices their rates are not going
         * out. A double booking is not. The default belongs on the recoverable
         * side, and turning it on is a deliberate act after looking at what was
         * imported.
         */
        $this->assertFalse($account->sync_availability);
        $this->assertFalse($account->sync_rates);

        // Importing, though, is the point of connecting, so that stays on.
        $this->assertTrue($account->import_reservations);
    }

    public function test_the_pull_endpoint_persists_progress_for_the_status_screen(): void
    {
        $this->property('Yellow Room');
        $account = $this->account();

        $this->fakeHostex(['properties' => [['id' => 'hx-1', 'title' => 'Yellow Room']]]);

        $this->postJson("/api/v1/channels/{$account->getKey()}/pull", ['full' => true])
            ->assertStatus(202)->assertJsonPath('data.status', 'queued');
        $this->artisan('channels:watch', ['--once' => true])->assertSuccessful();
        $this->getJson('/api/v1/channels')->assertOk()
            ->assertJsonStructure(['data' => [['last_pull_result' => ['listings', 'reservations', 'messages', 'at']]]]);
    }

    public function test_the_scheduled_command_pulls_each_account_inside_its_own_tenant(): void
    {
        $firstProperty = $this->property('Yellow Room');
        $first = $this->account();
        $this->map($first, $firstProperty);

        // A second company, with its own Hostex account and its own property of
        // the same name — the case where a mapping that leaked across tenants
        // would attach one company's bookings to another's flat.
        $secondProperty = $this->property('Yellow Room');
        $second = $this->account();
        $this->map($second, $secondProperty);

        $this->fakeHostex(['properties' => [['id' => 'hx-1', 'title' => 'Yellow Room']]]);

        $this->artisan('channels:pull', ['--full' => true])->assertSuccessful();

        $mappings = ChannelListing::query()->withoutGlobalScope('organization')->get();

        $this->assertCount(2, $mappings, 'Each company gets its own mapping.');
        $this->assertEqualsCanonicalizing(
            [$first->organization_id, $second->organization_id],
            $mappings->pluck('organization_id')->all(),
        );

        foreach ($mappings as $mapping) {
            // Mapped within its own organization, never across.
            $this->assertSame(
                $mapping->organization_id,
                Property::query()->withoutGlobalScope('organization')
                    ->whereKey($mapping->property_id)->sole()->organization_id,
            );
        }
    }

    private function map(ChannelAccount $account, Property $property): void
    {
        ChannelListing::query()->create([
            'channel_account_id' => $account->id, 'external_listing_id' => 'hx-1',
            'listing_id' => $property->listings()->first()->id, 'property_id' => $property->id,
        ]);
    }

    // ---------------------------------------------------------------- fixtures

    private function property(string $name): Property
    {
        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);
        $this->user = $this->createUser($this->organization);
        $this->actingAsUser($this->user, $this->organization);

        $property = $this->app->make(PropertyService::class)->create([
            'name' => $name,
            'property_type' => 'apartment',
            'address_line_1' => 'Rua dos Remédios 12',
            'postal_code' => '1100-513',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'max_occupancy' => 2,
            'base_rate' => 9000,
        ]);

        $this->app->make(PropertyService::class)->activate($property);

        return $property->fresh();
    }

    /**
     * Another property in the organization the last one created.
     */
    private function alsoProperty(string $name): Property
    {
        $property = $this->app->make(PropertyService::class)->create([
            'name' => $name,
            'property_type' => 'apartment',
            'address_line_1' => 'Rua da Prata 4',
            'postal_code' => '1100-052',
            'city' => 'Lisbon',
            'country_code' => 'PT',
            'max_occupancy' => 2,
            'base_rate' => 9000,
        ]);

        $this->app->make(PropertyService::class)->activate($property);

        return $property->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function account(array $attributes = []): ChannelAccount
    {
        return ChannelAccount::query()->create($attributes + [
            'organization_id' => $this->organization->getKey(),
            'channel' => 'hostex',
            'name' => 'Hostex',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'credentials' => ['access_token' => 'token-for-tests'],
            'import_reservations' => true,
            'sync_messages' => false,
        ]);
    }

    /**
     * Hostex's shapes, answered per path.
     *
     * One closure rather than several patterns: `Http::fake()` merges stubs and
     * the first match wins, so a second call naming the same pattern never takes
     * effect — a trap that silently makes half a test meaningless.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $data
     * @param  array<string, mixed>|null  $conversationsError
     */
    private function fakeHostex(array $data, ?array $conversationsError = null): void
    {
        foreach ($data['properties'] ?? [] as $index => $property) {
            $data['properties'][$index]['channels'] = [['channel_type' => 'airbnb', 'listing_id' => 'ota-'.$property['id'], 'currency' => 'EUR']];
            $data['listings'][] = ['channel_type' => 'airbnb', 'listing_id' => 'ota-'.$property['id'], 'title' => $property['title']];
        }
        Http::fake(function ($request) use ($data, $conversationsError) {
            $url = (string) $request->url();

            if (str_contains($url, '/conversations')) {
                return Http::response(
                    $conversationsError ?? ['data' => ['conversations' => []]],
                    200,
                );
            }

            if (str_contains($url, '/listings/calendar')) {
                return Http::response(['data' => ['listings' => array_map(fn ($listing) => $listing + ['calendar' => []], $request['listings'])]]);
            }
            if (str_contains($url, '/availabilities')) {
                return Http::response(['data' => ['properties' => array_map(fn ($property) => ['id' => $property['id'], 'availabilities' => []], $data['properties'] ?? [])]]);
            }
            if (str_contains($url, '/listings/airbnb/price_and_rules')) {
                return Http::response(['data' => ['listing_currency' => 'EUR', 'base_price' => 100]]);
            }
            foreach (['properties', 'reservations', 'listings', 'transactions'] as $collection) {
                if (str_contains($url, '/'.$collection)) {
                    return Http::response(['data' => [$collection => $data[$collection] ?? []]], 200);
                }
            }

            return Http::response(['data' => []], 200);
        });
    }
}
