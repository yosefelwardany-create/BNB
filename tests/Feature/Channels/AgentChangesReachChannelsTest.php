<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Agents\Enums\AgentCapability;
use App\Domain\Agents\Models\AgentAction;
use App\Domain\Agents\Services\AgentActions;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Availability\Models\CalendarBlock;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The agent writes the same rows a person does, so it must announce them too.
 *
 * `CalendarController` was fixed to emit domain events for rate and
 * restriction changes, which is what gets them to the channels. The agent
 * surface writes the very same tables — `calendar_blocks` and the
 * `rate_override` on `calendar_days` — through its own service and announced
 * none of it. So an operator who asked the agent to close next weekend, or to
 * drop the rate, got a correct local calendar and an OTA still selling the old
 * one. Exactly the bug the calendar fix was for, reachable by a different door.
 *
 * Driven through `AgentActions::approve()` rather than by dispatching the
 * events directly, because the defect was never in the listener: it was that
 * this write path never spoke to it.
 */
class AgentChangesReachChannelsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    private ChannelListing $mapping;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);
        $this->admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);
        $this->actingAsUser($this->admin, $this->organization);

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
            'is_primary' => true,
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
    }

    public function test_a_block_the_agent_creates_marks_availability(): void
    {
        $this->allow(AgentCapability::BlockDates);

        $from = CarbonImmutable::today()->addDays(20);
        $to = $from->addDays(2);

        $this->approve(AgentCapability::BlockDates, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'reason' => 'Boiler replacement',
        ]);

        $this->mapping->refresh();

        $this->assertTrue($this->mapping->availability_dirty);
        $this->assertFalse($this->mapping->rates_dirty, 'Closing nights changes no price.');
        $this->assertSame($from->toDateString(), $this->mapping->dirty_from->toDateString());
    }

    public function test_unblocking_part_of_a_longer_block_marks_the_nights_it_freed(): void
    {
        $this->allow(AgentCapability::UnblockDates);

        // A fortnight closed, of which the agent is asked to re-open two days
        // in the middle. The block is split rather than deleted.
        $start = CarbonImmutable::today()->addDays(30);
        $end = $start->addDays(14);

        CalendarBlock::query()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'kind' => CalendarBlock::KIND_MANUAL,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]);

        $openFrom = $start->addDays(5);

        $this->approve(AgentCapability::UnblockDates, [
            'from' => $openFrom->toDateString(),
            'to' => $openFrom->addDay()->toDateString(),
        ]);

        $this->mapping->refresh();

        $this->assertTrue($this->mapping->availability_dirty);

        // The window announced is the block's dates *before* the split, which
        // is the only window guaranteed to contain the freed nights. Reading
        // the trimmed block's own dates afterwards would describe the nights
        // it kept and leave the re-opened ones closed on every channel — the
        // direction that costs money.
        $this->assertSame($start->toDateString(), $this->mapping->dirty_from->toDateString());
        $this->assertSame($end->toDateString(), $this->mapping->dirty_to->toDateString());

        $this->assertTrue(
            $this->mapping->dirty_from->lessThanOrEqualTo($openFrom)
                && $this->mapping->dirty_to->greaterThan($openFrom),
            'The freed nights have to fall inside the window that gets pushed.',
        );
    }

    public function test_a_rate_the_agent_sets_marks_rates(): void
    {
        $this->allow(AgentCapability::SetRate);

        $from = CarbonImmutable::today()->addDays(40);
        $to = $from->addDays(3);

        $this->approve(AgentCapability::SetRate, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'amount_minor_units' => 13500,
        ]);

        $this->mapping->refresh();

        $this->assertTrue($this->mapping->rates_dirty, 'A rate the agent set is still a rate change.');
        $this->assertFalse(
            $this->mapping->availability_dirty,
            'It changes the price, not what can be sold.',
        );
        $this->assertSame($from->toDateString(), $this->mapping->dirty_from->toDateString());
        $this->assertSame($to->toDateString(), $this->mapping->dirty_to->toDateString());
    }

    // ------------------------------------------------------------------

    private function allow(AgentCapability $capability): void
    {
        $this->app->make(AgentBriefStore::class)->save($this->property, [
            'enabled' => true,
            'bot_name' => 'Alex',
            'may_do' => [$capability->value],
        ]);

        $this->property = $this->property->fresh();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function approve(AgentCapability $capability, array $arguments): AgentAction
    {
        $action = AgentAction::query()->create([
            'property_id' => $this->property->getKey(),
            'capability' => $capability,
            'arguments' => $arguments,
            'summary' => 'Requested in a test.',
            'expires_at' => CarbonImmutable::now()->addHours(48),
        ]);

        return $this->app->make(AgentActions::class)->approve($action, $this->admin);
    }
}
