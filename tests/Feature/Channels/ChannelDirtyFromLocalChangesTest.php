<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Availability\Models\CalendarBlock;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Channels\Services\ChannelSynchroniser;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Pricing\Models\PricingRule;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Local rate and restriction changes must reach the channels.
 *
 * This file exists because for a long time they did not. Four listeners on the
 * reservation events marked `availability` and nothing else ever marked
 * `rates`, so only inventory consumed by a booking was ever pushed: a price, a
 * minimum stay, an arrival rule or a night closed by hand stayed purely local
 * and the OTA went on selling the old number. `scopeDirty()` cannot rescue
 * that — it only finds mappings already flagged — so the change was invisible
 * to `channels:push-pending` forever, not merely late.
 *
 * Every test here therefore drives a real HTTP endpoint and then asserts on
 * the mapping row, because the bug was precisely that the write path never
 * reached the marking code. A unit test of the listener would have passed
 * throughout.
 */
class ChannelDirtyFromLocalChangesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    private Listing $secondListing;

    private ChannelListing $mapping;

    private ChannelListing $secondMapping;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'cleaning_fee' => 0,
            'max_occupancy' => 4,
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);

        // A second listing on the same property, so the rate-plan case can
        // show that a property-scoped change reaches every one of them and not
        // just the one that happened to be edited.
        $this->secondListing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);

        $account = ChannelAccount::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel' => 'airbnb',
            'name' => 'Airbnb — main account',
            'status' => ChannelAccount::STATUS_CONNECTED,
            'sync_availability' => true,
            'sync_rates' => true,
        ]);

        $this->mapping = ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $account->getKey(),
            'listing_id' => $this->listing->getKey(),
            'property_id' => $this->property->getKey(),
            'external_listing_id' => 'ext-listing-1',
        ]);

        $this->secondMapping = ChannelListing::query()->create([
            'organization_id' => $this->organization->getKey(),
            'channel_account_id' => $account->getKey(),
            'listing_id' => $this->secondListing->getKey(),
            'property_id' => $this->property->getKey(),
            'external_listing_id' => 'ext-listing-2',
        ]);

        $this->admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);
    }

    // ------------------------------------------------------------------
    // Calendar days: which payload a column belongs to
    // ------------------------------------------------------------------

    public function test_a_price_edit_marks_rates_and_not_availability(): void
    {
        $this->updateDays(['rate_override' => 18000])->assertOk();

        $this->mapping->refresh();

        $this->assertTrue($this->mapping->rates_dirty, 'A price change must mark the rates payload.');
        $this->assertFalse(
            $this->mapping->availability_dirty,
            'A price change does not alter what can be sold, so availability must be left alone.',
        );
    }

    public function test_an_arrival_rule_edit_marks_availability_and_not_rates(): void
    {
        $this->updateDays(['closed_to_arrival' => true])->assertOk();

        $this->mapping->refresh();

        $this->assertTrue($this->mapping->availability_dirty);
        $this->assertFalse(
            $this->mapping->rates_dirty,
            'Closing arrivals changes no price, so the rates payload must not be re-sent.',
        );
    }

    public function test_a_minimum_stay_edit_marks_both_payloads(): void
    {
        // Min-stay is carried on the availability *and* the rates payload, so
        // marking only one leaves half the channels wrong. This is the case a
        // hand-written check at the call site always gets wrong.
        $this->updateDays(['minimum_nights' => 5])->assertOk();

        $this->mapping->refresh();

        $this->assertTrue($this->mapping->availability_dirty);
        $this->assertTrue($this->mapping->rates_dirty);
    }

    public function test_a_bulk_edit_marks_the_whole_range_once(): void
    {
        $from = CarbonImmutable::today()->addDays(30);
        $to = $from->addDays(60);

        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson("/api/v1/calendar/listings/{$this->listing->getKey()}/days", [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'rate_override' => 25000,
            ])
            ->assertOk();

        $this->mapping->refresh();

        $this->assertSame($from->toDateString(), $this->mapping->dirty_from->toDateString());
        $this->assertSame($to->toDateString(), $this->mapping->dirty_to->toDateString());
    }

    // ------------------------------------------------------------------
    // Blocks
    // ------------------------------------------------------------------

    public function test_creating_a_block_marks_availability_across_its_dates(): void
    {
        $start = CarbonImmutable::today()->addDays(10);
        $end = $start->addDays(4);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/calendar/blocks', [
                'property_id' => $this->property->getKey(),
                'kind' => CalendarBlock::KIND_MAINTENANCE,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ])
            ->assertCreated();

        $this->mapping->refresh();

        $this->assertTrue($this->mapping->availability_dirty);
        $this->assertFalse($this->mapping->rates_dirty, 'A block changes no price.');
        $this->assertSame($start->toDateString(), $this->mapping->dirty_from->toDateString());
        $this->assertSame($end->toDateString(), $this->mapping->dirty_to->toDateString());

        // The block hangs off the property, so every listing on it is closed.
        $this->secondMapping->refresh();
        $this->assertTrue($this->secondMapping->availability_dirty);
    }

    public function test_removing_a_block_marks_availability_across_the_freed_dates(): void
    {
        $start = CarbonImmutable::today()->addDays(20);
        $end = $start->addDays(3);

        $block = CalendarBlock::query()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'kind' => CalendarBlock::KIND_OWNER_STAY,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]);

        // Created directly, so nothing is dirty yet and the assertion below is
        // about the removal rather than the creation.
        $this->assertFalse($this->mapping->fresh()->availability_dirty);

        $this->actingAsUser($this->admin, $this->organization)
            ->deleteJson("/api/v1/calendar/blocks/{$block->getKey()}")
            ->assertOk();

        $this->mapping->refresh();

        // The more urgent direction of the two: these nights are sellable
        // again, and every hour they stay closed on a channel is revenue lost.
        $this->assertTrue($this->mapping->availability_dirty);
        $this->assertSame($start->toDateString(), $this->mapping->dirty_from->toDateString());
        $this->assertSame($end->toDateString(), $this->mapping->dirty_to->toDateString());
    }

    // ------------------------------------------------------------------
    // Pricing
    // ------------------------------------------------------------------

    public function test_a_rate_plan_change_marks_every_listing_of_its_property(): void
    {
        // A plan is attached to a property, never to one listing, which is why
        // this cannot go through markListingDirty().
        $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/rate-plans', [
                'name' => 'Non-refundable',
                'property_id' => $this->property->getKey(),
                'currency' => 'EUR',
            ])
            ->assertCreated();

        foreach ([$this->mapping, $this->secondMapping] as $mapping) {
            $mapping->refresh();

            $this->assertTrue(
                $mapping->rates_dirty,
                'Every listing of the property the plan covers must be marked.',
            );
            $this->assertFalse($mapping->availability_dirty);
        }
    }

    public function test_withdrawing_a_rate_plan_marks_rates_too(): void
    {
        $created = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/rate-plans', [
                'name' => 'Weekly',
                'property_id' => $this->property->getKey(),
                'currency' => 'EUR',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->mapping->forceFill(['rates_dirty' => false])->save();

        $this->actingAsUser($this->admin, $this->organization)
            ->deleteJson("/api/v1/rate-plans/{$created}")
            ->assertOk();

        // Taking a plan off sale moves the price on every night it priced, so
        // a withdrawal is as much a change as a repricing.
        $this->assertTrue($this->mapping->fresh()->rates_dirty);
    }

    public function test_a_pricing_rule_marks_only_the_nights_it_prices(): void
    {
        $stayFrom = CarbonImmutable::today()->addDays(100);
        $stayTo = $stayFrom->addDays(30);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/pricing-rules', [
                'name' => 'High season',
                'kind' => PricingRule::KIND_SEASONAL,
                'property_id' => $this->property->getKey(),
                'adjustment_type' => 'increase_percent',
                'adjustment_value' => 1500,
                // In force from today, but pricing next season. The window
                // that matters is the stay window, not the effective one: a
                // rule that confused the two would push today's nights and
                // leave the priced ones stale.
                'effective_from' => CarbonImmutable::today()->toDateString(),
                'effective_to' => CarbonImmutable::today()->addDays(10)->toDateString(),
                'stay_from' => $stayFrom->toDateString(),
                'stay_to' => $stayTo->toDateString(),
            ])
            ->assertCreated();

        $this->mapping->refresh();

        $this->assertTrue($this->mapping->rates_dirty);
        $this->assertSame($stayFrom->toDateString(), $this->mapping->dirty_from->toDateString());
        $this->assertSame($stayTo->toDateString(), $this->mapping->dirty_to->toDateString());
    }

    public function test_a_pricing_rule_without_a_stay_window_widens_to_the_horizon(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/pricing-rules', [
                'name' => 'Everything, always',
                'kind' => PricingRule::KIND_CUSTOM,
                'property_id' => $this->property->getKey(),
                'adjustment_type' => 'increase_percent',
                'adjustment_value' => 500,
            ])
            ->assertCreated();

        $this->mapping->refresh();

        $horizon = CarbonImmutable::today()
            ->addDays((int) config('pms.availability.horizon_days', 730));

        $this->assertTrue($this->mapping->rates_dirty);
        $this->assertSame(CarbonImmutable::today()->toDateString(), $this->mapping->dirty_from->toDateString());
        $this->assertSame($horizon->toDateString(), $this->mapping->dirty_to->toDateString());
    }

    // ------------------------------------------------------------------
    // The contract: bookkeeping can never fail the save
    // ------------------------------------------------------------------

    public function test_a_failure_marking_channels_is_logged_and_does_not_fail_the_save(): void
    {
        Log::spy();

        $this->mock(
            ChannelSynchroniser::class,
            fn ($mock) => $mock->shouldReceive('markListingDirty')
                ->andThrow(new \RuntimeException('channel bookkeeping unavailable')),
        );

        $this->updateDays(['rate_override' => 21000])->assertOk();

        // The operator's correction stands. Channel marking is bookkeeping
        // about a save that already happened, and the scheduler's full-horizon
        // sweep is the safety net for a lost flag; a lost edit has no net.
        $this->assertDatabaseHas('calendar_days', [
            'listing_id' => $this->listing->getKey(),
            'rate_override' => 21000,
        ]);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains(
                $message,
                'Could not mark channels dirty',
            ));
    }

    public function test_a_pricing_rule_that_moves_its_window_marks_both(): void
    {
        $created = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/pricing-rules', [
                'name' => 'Summer',
                'kind' => PricingRule::KIND_SEASONAL,
                'property_id' => $this->property->getKey(),
                'adjustment_type' => 'increase_percent',
                'adjustment_value' => 1000,
                'stay_from' => '2027-07-01',
                'stay_to' => '2027-07-31',
            ])
            ->assertCreated()
            ->json('data.id');

        // Cleared so the assertion is about the move alone and not about the
        // window the creation already marked.
        $this->mapping->forceFill([
            'rates_dirty' => false,
            'availability_dirty' => false,
            'dirty_from' => null,
            'dirty_to' => null,
        ])->save();

        $this->actingAsUser($this->admin, $this->organization)
            ->patchJson("/api/v1/pricing-rules/{$created}", [
                'stay_from' => '2027-08-01',
                'stay_to' => '2027-08-31',
            ])
            ->assertOk();

        $this->mapping->refresh();

        $this->assertTrue($this->mapping->rates_dirty);

        // Both months. July was priced by this rule and is not any more, so
        // its nights have to be repushed too — announcing only August leaves
        // July on a channel at a price nothing in the system now produces.
        // The same trap a moved booking sets, which the reservation path
        // already avoids by carrying its previous dates.
        $this->assertSame('2027-07-01', $this->mapping->dirty_from->toDateString());
        $this->assertSame('2027-08-31', $this->mapping->dirty_to->toDateString());
    }

    // ------------------------------------------------------------------

    /**
     * Edit a single near-term date on the main listing.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function updateDays(array $attributes): TestResponse
    {
        $date = CarbonImmutable::today()->addDays(7)->toDateString();

        return $this->actingAsUser($this->admin, $this->organization)
            ->patchJson("/api/v1/calendar/listings/{$this->listing->getKey()}/days", $attributes + [
                'from' => $date,
                'to' => $date,
            ]);
    }
}
