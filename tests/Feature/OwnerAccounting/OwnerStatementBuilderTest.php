<?php

declare(strict_types=1);

namespace Tests\Feature\OwnerAccounting;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\OwnerAccounting\Models\OwnerStatementLine;
use App\Domain\OwnerAccounting\Services\OwnerStatementBuilder;
use App\Domain\Owners\Models\ManagementAgreement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Services\OwnerDirectory;
use App\Domain\Owners\Services\OwnershipLedger;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner statements.
 *
 * The most scrutinised document the product produces, and the one where being
 * quietly wrong costs a client relationship rather than a support ticket.
 *
 * What these protect:
 *
 *  - Revenue is attributed **night by night**, so a stay spanning a period
 *    boundary or a change of ownership splits correctly instead of landing
 *    wholly with whoever owned the property on the check-in date.
 *  - The management fee follows the agreement **in force for that property**,
 *    and a statement keeps the terms it was built under.
 *  - An approved statement is frozen, and what it consumed cannot be swept
 *    into a second one.
 *  - A deficit carries forward rather than being written off or demanded back.
 */
class OwnerStatementBuilderTest extends TestCase
{
    use RefreshDatabase;

    private OwnerStatementBuilder $builder;

    private OwnershipLedger $ownership;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = $this->app->make(OwnerStatementBuilder::class);
        $this->ownership = $this->app->make(OwnershipLedger::class);
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
    }

    public function test_a_sole_owner_receives_the_whole_revenue_less_the_fee(): void
    {
        $owner = $this->ownerWith(100);
        $this->agreement($owner, 20);

        // Four nights at 100.00.
        $this->book(5, 4);

        $statement = $this->buildFor($owner);

        $this->assertSame(40000, (int) $statement->gross_revenue);
        $this->assertSame(8000, (int) $statement->management_fee);
        $this->assertSame(32000, (int) $statement->net_due);
        $this->assertSame(32000, (int) $statement->payout_amount);
        $this->assertSame(4, (int) $statement->nights_sold);
        $this->assertSame(1, (int) $statement->reservations_count);
    }

    public function test_the_lines_sum_to_the_total(): void
    {
        $owner = $this->ownerWith(100);
        $this->agreement($owner, 20);

        $this->book(5, 3);

        $statement = $this->buildFor($owner);

        // The first thing an owner does is add up the column. Amounts are
        // signed precisely so that it works with no rules to remember.
        $sum = $statement->lines->sum(fn (OwnerStatementLine $line): int => (int) $line->amount);

        $this->assertSame((int) $statement->closing_balance, $sum);
    }

    public function test_joint_owners_each_receive_their_share(): void
    {
        $majority = $this->ownerWith(60, 'Majority');
        $minority = $this->ownerWith(40, 'Minority');

        $this->agreement($majority, 20);
        $this->agreement($minority, 20);

        $this->book(5, 4);

        $first = $this->buildFor($majority);
        $second = $this->buildFor($minority);

        $this->assertSame(24000, (int) $first->gross_revenue);
        $this->assertSame(16000, (int) $second->gross_revenue);

        // Together they account for the whole property, and neither is charged
        // commission on the other's share.
        $this->assertSame(40000, (int) $first->gross_revenue + (int) $second->gross_revenue);
        $this->assertSame(4800, (int) $first->management_fee);
        $this->assertSame(3200, (int) $second->management_fee);
    }

    public function test_a_co_owner_sees_the_whole_figure_alongside_their_share(): void
    {
        $owner = $this->ownerWith(60);
        $this->ownerWith(40, 'Other');
        $this->agreement($owner, 20);

        $this->book(5, 1);

        $statement = $this->buildFor($owner);

        $revenueLine = $statement->lines
            ->firstWhere('category', OwnerStatementLine::REVENUE);

        $this->assertSame(6000, (int) $revenueLine->amount);

        // Both numbers, so a co-owner can see what the property earned as well
        // as what portion is theirs.
        $this->assertSame(10000, (int) $revenueLine->full_amount);
        $this->assertEqualsWithDelta(60.0, (float) $revenueLine->ownership_percentage, 0.0001);
    }

    public function test_a_stay_spanning_the_period_boundary_is_split_by_night(): void
    {
        $owner = $this->ownerWith(100);
        $this->agreement($owner, 0);

        // Four nights on days 10, 11, 12 and 13.
        $this->book(10, 4);

        // A period that opens on day 12, cutting the stay in half.
        $statement = $this->buildFor(
            $owner,
            from: CarbonImmutable::today()->addDays(12),
            to: CarbonImmutable::today()->addDays(30),
        );

        // Only the two nights inside the window. Attributing the whole booking
        // to its check-in date would have put all four in the wrong period.
        $this->assertSame(2, (int) $statement->nights_sold);
        $this->assertSame(20000, (int) $statement->gross_revenue);
    }

    public function test_revenue_follows_the_share_in_force_on_each_night(): void
    {
        $seller = $this->ownerWith(100, 'Seller');

        // The seller's share ends after two nights of the stay.
        $sellerShare = $seller->ownerships()->first();
        $this->ownership->end($sellerShare, CarbonImmutable::today()->addDays(6));

        $buyer = $this->app->make(OwnerDirectory::class)->create([
            'first_name' => 'Buyer', 'last_name' => 'Owner',
        ]);

        $this->ownership->assign($this->property, $buyer, [
            'ownership_percentage' => 100,
            'starts_on' => CarbonImmutable::today()->addDays(7)->toDateString(),
        ]);

        $this->agreement($seller, 0);
        $this->agreement($buyer, 0);

        // Nights on days 5, 6, 7, 8.
        $this->book(5, 4);

        $sellerStatement = $this->buildFor($seller);
        $buyerStatement = $this->buildFor($buyer);

        // A property sold mid-stay does not retroactively give the buyer the
        // seller's nights.
        $this->assertSame(2, (int) $sellerStatement->nights_sold);
        $this->assertSame(2, (int) $buyerStatement->nights_sold);
        $this->assertSame(20000, (int) $sellerStatement->gross_revenue);
        $this->assertSame(20000, (int) $buyerStatement->gross_revenue);
    }

    public function test_an_owner_billable_expense_is_deducted_and_explained(): void
    {
        $owner = $this->ownerWith(100);
        $this->agreement($owner, 0);

        $this->book(5, 2);

        $this->expense($owner, amount: 7500, markup: 750, description: 'Replace the boiler thermostat');

        $statement = $this->buildFor($owner);

        $this->assertSame(8250, (int) $statement->expenses_total);
        $this->assertSame(20000 - 8250, (int) $statement->net_due);

        $line = $statement->lines->firstWhere('category', OwnerStatementLine::EXPENSE);

        $this->assertSame(-8250, (int) $line->amount);

        // The underlying cost is visible, not folded silently into the total.
        $this->assertStringContainsString('handling charge', (string) $line->explanation);
    }

    public function test_an_expense_the_manager_bears_is_not_billed_to_the_owner(): void
    {
        $owner = $this->ownerWith(100);
        $this->agreement($owner, 0);

        $this->book(5, 2);

        $this->expense($owner, amount: 5000, billableTo: 'manager');

        $statement = $this->buildFor($owner);

        $this->assertSame(0, (int) $statement->expenses_total);
        $this->assertSame(20000, (int) $statement->net_due);
    }

    public function test_a_draft_expense_is_not_billed_until_it_is_approved(): void
    {
        $owner = $this->ownerWith(100);
        $this->agreement($owner, 0);

        $this->book(5, 2);

        $this->expense($owner, amount: 5000, status: 'draft');

        $statement = $this->buildFor($owner);

        // Somebody's intention is not a cost the owner has agreed to bear.
        $this->assertSame(0, (int) $statement->expenses_total);
    }

    public function test_a_deficit_is_carried_forward_rather_than_demanded_back(): void
    {
        $owner = $this->ownerWith(100);
        $this->agreement($owner, 0);

        $this->book(5, 1);
        $this->expense($owner, amount: 40000, description: 'New boiler');

        $statement = $this->buildFor($owner);

        $this->assertSame(-30000, (int) $statement->net_due);
        $this->assertSame(-30000, (int) $statement->closing_balance);

        // Nothing is paid out, and nothing is invoiced back.
        $this->assertSame(0, (int) $statement->payout_amount);
        $this->assertTrue($statement->isInDeficit());
    }

    public function test_the_next_period_recovers_a_carried_deficit(): void
    {
        $owner = $this->ownerWith(100);
        $this->agreement($owner, 0);

        $this->book(5, 1);
        $this->expense($owner, amount: 40000, description: 'New boiler');

        $first = $this->buildFor($owner);
        $this->builder->approve($first);

        // A better month.
        $this->book(40, 5);

        $second = $this->buildFor(
            $owner,
            from: CarbonImmutable::today()->addDays(35),
            to: CarbonImmutable::today()->addDays(60),
        );

        $this->assertSame(-30000, (int) $second->opening_balance);
        $this->assertSame(50000, (int) $second->gross_revenue);

        // The earlier shortfall is recovered rather than written off.
        $this->assertSame(20000, (int) $second->closing_balance);
        $this->assertSame(20000, (int) $second->payout_amount);
    }

    public function test_a_reserve_is_withheld_but_never_deepens_a_deficit(): void
    {
        $owner = $this->ownerWith(100);
        $owner->forceFill(['reserve_amount' => 15000])->save();
        $this->agreement($owner, 0);

        $this->book(5, 4);

        $statement = $this->buildFor($owner->fresh());

        $this->assertSame(15000, (int) $statement->reserve_withheld);
        $this->assertSame(40000, (int) $statement->closing_balance);
        $this->assertSame(25000, (int) $statement->payout_amount);
    }

    public function test_a_reserve_is_not_withheld_from_an_owner_already_in_deficit(): void
    {
        $owner = $this->ownerWith(100);
        $owner->forceFill(['reserve_amount' => 15000])->save();
        $this->agreement($owner, 0);

        $this->book(5, 1);
        $this->expense($owner, amount: 30000);

        $statement = $this->buildFor($owner->fresh());

        // Holding back a float from somebody who already owes money would
        // deepen the hole rather than protect against it.
        $this->assertSame(0, (int) $statement->reserve_withheld);
    }

    public function test_no_agreement_means_no_fee_rather_than_a_guessed_one(): void
    {
        $owner = $this->ownerWith(100);

        $this->book(5, 2);

        $statement = $this->buildFor($owner);

        $this->assertSame(0, (int) $statement->management_fee);
        $this->assertSame(20000, (int) $statement->net_due);
        $this->assertNull($statement->agreement_snapshot);
    }

    public function test_the_statement_keeps_the_terms_it_was_built_under(): void
    {
        $owner = $this->ownerWith(100);
        $agreement = $this->agreement($owner, 20);

        $this->book(5, 4);

        $statement = $this->buildFor($owner);
        $this->builder->approve($statement);

        // Renegotiated afterwards.
        $agreement->forceFill(['commission_rate' => 35])->save();

        $statement->refresh();

        // An agreement changed in June must not restate March.
        $this->assertSame(8000, (int) $statement->management_fee);
        $this->assertEqualsWithDelta(20.0, (float) $statement->agreement_snapshot['commission_rate'], 0.0001);
    }

    public function test_a_draft_can_be_rebuilt_freely(): void
    {
        $owner = $this->ownerWith(100);
        $this->agreement($owner, 20);

        $this->book(5, 2);

        $first = $this->buildFor($owner);
        $this->assertSame(20000, (int) $first->gross_revenue);

        $this->book(10, 2);

        $rebuilt = $this->buildFor($owner);

        // Replaced, not appended to.
        $this->assertSame($first->getKey(), $rebuilt->getKey());
        $this->assertSame(40000, (int) $rebuilt->gross_revenue);
        $this->assertSame(4, (int) $rebuilt->nights_sold);
    }

    public function test_an_approved_statement_cannot_be_rebuilt_or_edited(): void
    {
        $owner = $this->ownerWith(100);
        $this->agreement($owner, 20);

        $this->book(5, 2);

        $statement = $this->builder->approve($this->buildFor($owner));

        $this->assertSame(OwnerStatement::STATUS_APPROVED, $statement->status);

        // A statement the owner has seen is fixed. A correction is a new
        // statement, never an edit.
        try {
            $this->buildFor($owner);
            $this->fail('Rebuilding an approved statement should have been refused.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('issued', $exception->getMessage());
        }

        $this->expectException(\RuntimeException::class);

        $statement->forceFill(['net_due' => 999999])->save();
    }

    public function test_approving_marks_what_it_consumed_so_it_cannot_be_billed_twice(): void
    {
        $owner = $this->ownerWith(100);
        $this->agreement($owner, 0);

        $this->book(5, 2);
        $this->expense($owner, amount: 5000);

        $statement = $this->builder->approve($this->buildFor($owner));

        $consumed = DB::table('expenses')
            ->where('owner_statement_id', $statement->getKey())
            ->count();

        $this->assertSame(1, $consumed);

        // The same expense in a later period is not billed again.
        $next = $this->buildFor(
            $owner,
            from: CarbonImmutable::today()->addDays(35),
            to: CarbonImmutable::today()->addDays(60),
        );

        $this->assertSame(0, (int) $next->expenses_total);
    }

    public function test_a_cancelled_booking_earns_the_owner_nothing(): void
    {
        $owner = $this->ownerWith(100);
        $this->agreement($owner, 0);

        $reservation = $this->book(5, 3);

        $this->app->make(ReservationService::class)->cancel($reservation, 'Guest cancelled');

        $statement = $this->buildFor($owner);

        $this->assertSame(0, (int) $statement->gross_revenue);
        $this->assertSame(0, (int) $statement->nights_sold);
    }

    // ------------------------------------------------------------------

    private function ownerWith(float $share, string $name = 'Owner'): Owner
    {
        $owner = $this->app->make(OwnerDirectory::class)->create([
            'first_name' => $name,
            'last_name' => 'Test',
            'payout_currency' => 'EUR',
        ]);

        $this->ownership->assign($this->property, $owner, ['ownership_percentage' => $share]);

        return $owner->fresh();
    }

    private function agreement(Owner $owner, float $rate): ManagementAgreement
    {
        return ManagementAgreement::query()->create([
            'organization_id' => $this->organization->getKey(),
            'owner_id' => $owner->getKey(),
            'property_id' => $this->property->getKey(),
            'name' => 'Standard',
            'commission_model' => ManagementAgreement::PERCENT_OF_REVENUE,
            'commission_rate' => $rate,
            'currency' => 'EUR',
            'commission_on_accommodation' => true,
            'starts_on' => CarbonImmutable::today()->subYear()->toDateString(),
            'status' => 'active',
        ]);
    }

    private function expense(
        Owner $owner,
        int $amount,
        int $markup = 0,
        string $description = 'Repair',
        string $billableTo = 'owner',
        string $status = 'approved',
    ): string {
        $id = (string) Str::ulid();

        DB::table('expenses')->insert([
            'id' => $id,
            'organization_id' => $this->organization->getKey(),
            'reference' => 'EXP-'.substr($id, -6),
            'property_id' => $this->property->getKey(),
            'owner_id' => $owner->getKey(),
            'expense_date' => CarbonImmutable::today()->addDays(6)->toDateString(),
            'category' => 'maintenance',
            'description' => $description,
            'amount' => $amount,
            'markup_amount' => $markup,
            'tax_amount' => 0,
            'currency' => 'EUR',
            'billable_to' => $billableTo,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function buildFor(
        Owner $owner,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): OwnerStatement {
        return $this->builder->build(
            $owner,
            $from ?? CarbonImmutable::today(),
            $to ?? CarbonImmutable::today()->addDays(30),
        );
    }

    private function book(int $startOffset, int $nights): Reservation
    {
        return $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $this->listing,
            checkIn: $this->date($startOffset),
            checkOut: $this->date($startOffset + $nights),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Marta',
                'last_name' => 'Silva',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
            bookedAt: $this->date(-5),
        ));
    }

    private function date(int $offsetDays): CarbonImmutable
    {
        return CarbonImmutable::now($this->property->timezone)->startOfDay()->addDays($offsetDays);
    }
}
