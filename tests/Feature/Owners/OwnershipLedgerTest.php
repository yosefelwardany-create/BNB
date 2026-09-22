<?php

declare(strict_types=1);

namespace Tests\Feature\Owners;

use App\Domain\Organization\Models\Organization;
use App\Domain\Owners\Exceptions\OwnershipConflictException;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Models\PropertyOwnership;
use App\Domain\Owners\Services\OwnerDirectory;
use App\Domain\Owners\Services\OwnershipLedger;
use App\Domain\Properties\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who owns what, and for how long.
 *
 * The invariant under test is that no date is allocated more than 100%.
 * Without it an owner statement quietly pays out more than the property
 * earned, and the error surfaces months later as a reconciliation mismatch
 * nobody can trace back to its cause.
 *
 * Under-allocation is deliberately allowed: the manager may own the remainder,
 * or a share may not have been recorded yet, and refusing that would stop
 * people entering what they actually know.
 */
class OwnershipLedgerTest extends TestCase
{
    use RefreshDatabase;

    private OwnershipLedger $ledger;

    private Organization $organization;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = $this->app->make(OwnershipLedger::class);
        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
        ]);
    }

    public function test_a_sole_owner_holds_the_whole_property(): void
    {
        $owner = $this->owner('Whole');

        $share = $this->ledger->assign($this->property, $owner, ['ownership_percentage' => 100]);

        $this->assertSame(100.0, $share->share());

        // The first share recorded becomes the primary one: statements and
        // correspondence need a single addressee.
        $this->assertTrue($share->is_primary);

        $position = $this->ledger->positionOn($this->property, CarbonImmutable::today());

        $this->assertSame(100.0, $position['allocated']);
        $this->assertSame(0.0, $position['unallocated']);
    }

    public function test_joint_owners_can_split_a_property(): void
    {
        $this->ledger->assign($this->property, $this->owner('A'), ['ownership_percentage' => 60]);
        $this->ledger->assign($this->property, $this->owner('B'), ['ownership_percentage' => 40]);

        $position = $this->ledger->positionOn($this->property, CarbonImmutable::today());

        $this->assertCount(2, $position['shares']);
        $this->assertSame(100.0, $position['allocated']);
    }

    public function test_a_three_way_split_that_does_not_divide_evenly_is_accepted(): void
    {
        // 33.3333 three times is 99.9999. Decimal rounding must not be able to
        // manufacture a conflict out of an ordinary equal split.
        foreach (['A', 'B', 'C'] as $name) {
            $this->ledger->assign($this->property, $this->owner($name), [
                'ownership_percentage' => 33.3333,
            ]);
        }

        $position = $this->ledger->positionOn($this->property, CarbonImmutable::today());

        $this->assertCount(3, $position['shares']);
        $this->assertEqualsWithDelta(100.0, $position['allocated'], 0.001);
    }

    public function test_a_share_that_would_over_allocate_a_date_is_refused(): void
    {
        $this->ledger->assign($this->property, $this->owner('A'), ['ownership_percentage' => 70]);

        try {
            $this->ledger->assign($this->property, $this->owner('B'), ['ownership_percentage' => 40]);

            $this->fail('A 110% allocation should have been refused.');
        } catch (OwnershipConflictException $exception) {
            $this->assertNotEmpty($exception->conflicts());
            $this->assertSame(110.0, $exception->conflicts()[0]['total']);
        }

        // And nothing was written.
        $this->assertSame(1, PropertyOwnership::query()->count());
    }

    public function test_under_allocation_is_allowed_and_reported(): void
    {
        $this->ledger->assign($this->property, $this->owner('A'), ['ownership_percentage' => 60]);

        $position = $this->ledger->positionOn($this->property, CarbonImmutable::today());

        // The manager may hold the rest. Refusing this would stop somebody
        // recording the share they do know about.
        $this->assertSame(60.0, $position['allocated']);
        $this->assertSame(40.0, $position['unallocated']);
    }

    public function test_a_property_can_change_hands_without_a_conflict(): void
    {
        $seller = $this->owner('Seller');
        $buyer = $this->owner('Buyer');

        $this->ledger->assign($this->property, $seller, [
            'ownership_percentage' => 100,
            'ends_on' => CarbonImmutable::today()->addDays(30)->toDateString(),
        ]);

        // The buyer takes over the day after the seller's share ends. The two
        // never overlap, so 100% + 100% is fine.
        $this->ledger->assign($this->property, $buyer, [
            'ownership_percentage' => 100,
            'starts_on' => CarbonImmutable::today()->addDays(31)->toDateString(),
        ]);

        $before = $this->ledger->positionOn($this->property, CarbonImmutable::today()->addDays(10));
        $after = $this->ledger->positionOn($this->property, CarbonImmutable::today()->addDays(60));

        $this->assertSame($seller->getKey(), $before['shares']->first()->owner_id);
        $this->assertSame($buyer->getKey(), $after['shares']->first()->owner_id);
    }

    public function test_an_overlapping_handover_is_refused_on_the_days_that_overlap(): void
    {
        $this->ledger->assign($this->property, $this->owner('Seller'), [
            'ownership_percentage' => 100,
            'ends_on' => CarbonImmutable::today()->addDays(30)->toDateString(),
        ]);

        // A day early: the two shares now both cover day 30.
        $this->expectException(OwnershipConflictException::class);

        $this->ledger->assign($this->property, $this->owner('Buyer'), [
            'ownership_percentage' => 100,
            'starts_on' => CarbonImmutable::today()->addDays(30)->toDateString(),
        ]);
    }

    public function test_editing_a_share_is_checked_against_the_others(): void
    {
        $a = $this->ledger->assign($this->property, $this->owner('A'), ['ownership_percentage' => 50]);
        $this->ledger->assign($this->property, $this->owner('B'), ['ownership_percentage' => 50]);

        // Raising A to 60 would make 110 alongside B's unchanged 50.
        $this->expectException(OwnershipConflictException::class);

        $this->ledger->update($a, ['ownership_percentage' => 60]);
    }

    public function test_a_share_can_be_edited_without_conflicting_with_itself(): void
    {
        $share = $this->ledger->assign($this->property, $this->owner('A'), ['ownership_percentage' => 50]);

        // The candidate must be excluded from its own check, or any edit to a
        // 100% share would be refused.
        $updated = $this->ledger->update($share, ['ownership_percentage' => 100]);

        $this->assertSame(100.0, $updated->share());
    }

    public function test_only_one_share_is_primary(): void
    {
        $first = $this->ledger->assign($this->property, $this->owner('A'), ['ownership_percentage' => 50]);
        $second = $this->ledger->assign($this->property, $this->owner('B'), [
            'ownership_percentage' => 50,
            'is_primary' => true,
        ]);

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
    }

    public function test_ending_a_share_closes_it_rather_than_deleting_it(): void
    {
        $share = $this->ledger->assign($this->property, $this->owner('A'), ['ownership_percentage' => 100]);

        $this->ledger->end($share, CarbonImmutable::today());

        // Every statement already produced was attributed using this share.
        $this->assertNotNull(PropertyOwnership::query()->find($share->getKey()));
        $this->assertNotNull($share->fresh()->ends_on);

        // And it no longer counts towards tomorrow's allocation, so the
        // property can be reassigned.
        $tomorrow = $this->ledger->positionOn($this->property, CarbonImmutable::tomorrow());

        $this->assertSame(0.0, $tomorrow['allocated']);
    }

    public function test_an_owner_with_portal_access_sees_only_their_own_properties(): void
    {
        $directory = $this->app->make(OwnerDirectory::class);

        $owner = $this->owner('Portal', ['email' => 'owner-'.uniqid().'@example.test']);
        $theirs = $this->property;

        $someoneElses = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
        ]);

        $this->ledger->assign($theirs, $owner, ['ownership_percentage' => 100]);

        $result = $directory->enablePortalAccess($owner, sendInvitation: false);

        $membership = $result['membership']->fresh(['properties']);

        $this->assertTrue((bool) $membership->restricted_to_properties);
        $this->assertSame([$theirs->getKey()], $membership->properties->pluck('id')->all());
        $this->assertNotContains($someoneElses->getKey(), $membership->properties->pluck('id')->all());
    }

    public function test_buying_another_property_widens_portal_visibility(): void
    {
        $directory = $this->app->make(OwnerDirectory::class);

        $owner = $this->owner('Portal', ['email' => 'owner-'.uniqid().'@example.test']);

        $this->ledger->assign($this->property, $owner, ['ownership_percentage' => 100]);

        $directory->enablePortalAccess($owner, sendInvitation: false);

        $second = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
        ]);

        $this->ledger->assign($second, $owner, ['ownership_percentage' => 100]);
        $directory->syncPortalProperties($owner->fresh());

        $membership = $this->membershipOf($owner->fresh()->user, $this->organization)->fresh(['properties']);

        $this->assertCount(2, $membership->properties);
    }

    // ------------------------------------------------------------------

    private function owner(string $name, array $attributes = []): Owner
    {
        return $this->app->make(OwnerDirectory::class)->create(array_merge([
            'first_name' => $name,
            'last_name' => 'Owner',
        ], $attributes));
    }
}
