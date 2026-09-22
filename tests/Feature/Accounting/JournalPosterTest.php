<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\DataObjects\JournalDraft;
use App\Domain\Accounting\Exceptions\UnbalancedEntryException;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\DefaultChartOfAccounts;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The ledger.
 *
 * Everything financial in the product ends up here, so these are the tests that
 * matter most in the whole codebase. What they protect:
 *
 *  - An entry balances or it is not written. A ledger that accepted an
 *    unbalanced entry is wrong from that moment in a way no later
 *    reconciliation can detect.
 *  - A posted entry cannot be edited. Corrections are reversals, so the record
 *    keeps what was believed at the time.
 *  - The database refuses a line that is both a debit and a credit, or neither,
 *    independently of the application — a second line of defence that survives
 *    a bug in this code.
 */
class JournalPosterTest extends TestCase
{
    use RefreshDatabase;

    private JournalPoster $poster;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->poster = $this->app->make(JournalPoster::class);
        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);
    }

    public function test_a_balanced_entry_is_posted_with_a_readable_reference(): void
    {
        $entry = $this->poster->post(
            JournalDraft::for('Booking confirmed', 'reservation.confirmed')
                ->debit(DefaultChartOfAccounts::ACCOUNTS_RECEIVABLE, $this->eur(35000))
                ->credit(DefaultChartOfAccounts::DEFERRED_REVENUE, $this->eur(30000))
                ->credit(DefaultChartOfAccounts::CLEANING_REVENUE, $this->eur(5000)),
        );

        $this->assertNotNull($entry);
        $this->assertSame(JournalEntry::STATUS_POSTED, $entry->status);
        $this->assertStringStartsWith('JE-', $entry->reference);
        $this->assertNotNull($entry->posted_at);
        $this->assertSame(3, $entry->lines()->count());
        $this->assertSame('EUR', $entry->currency);
    }

    public function test_an_unbalanced_entry_is_refused_and_nothing_is_written(): void
    {
        $draft = JournalDraft::for('Wrong', 'test')
            ->debit(DefaultChartOfAccounts::CASH, $this->eur(10000))
            ->credit(DefaultChartOfAccounts::ACCOMMODATION_REVENUE, $this->eur(9000));

        try {
            $this->poster->post($draft);

            $this->fail('An unbalanced entry should have been refused.');
        } catch (UnbalancedEntryException $exception) {
            // The whole draft is in the message: this is always a bug in the
            // caller, and finding it should not require reconstructing the
            // entry by hand.
            $this->assertStringContainsString('1000', $exception->getMessage());
            $this->assertSame(1000, $exception->draft->imbalance());
        }

        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, JournalLine::query()->count());
    }

    public function test_an_empty_draft_is_not_an_error(): void
    {
        // A reprice that changed nothing, or a fee of zero, legitimately
        // produces no lines. Forcing callers to check first would put the
        // condition in a dozen places instead of one.
        $this->assertNull($this->poster->post(JournalDraft::for('Nothing happened', 'test')));

        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_a_zero_line_is_dropped_rather_than_written(): void
    {
        $entry = $this->poster->post(
            JournalDraft::for('Booking with no fee', 'test')
                ->debit(DefaultChartOfAccounts::ACCOUNTS_RECEIVABLE, $this->eur(30000))
                ->credit(DefaultChartOfAccounts::DEFERRED_REVENUE, $this->eur(30000))
                ->credit(DefaultChartOfAccounts::CLEANING_REVENUE, $this->eur(0)),
        );

        // The database's single-sided check would reject a zero line anyway;
        // dropping it means callers can build lines unconditionally.
        $this->assertSame(2, $entry->lines()->count());
    }

    public function test_a_negative_amount_flips_the_side_rather_than_posting_a_negative(): void
    {
        $entry = $this->poster->post(
            JournalDraft::for('Sides the wrong way round', 'test')
                ->debit(DefaultChartOfAccounts::CASH, $this->eur(-5000))
                ->debit(DefaultChartOfAccounts::REFUNDS, $this->eur(5000)),
        );

        $this->assertNotNull($entry);

        $cash = $entry->lines()
            ->whereRelation('account', 'system_key', DefaultChartOfAccounts::CASH)
            ->first();

        // Direction is expressed by the side, never by the sign: the database
        // constraint requires non-negative amounts.
        $this->assertSame(0, (int) $cash->debit);
        $this->assertSame(5000, (int) $cash->credit);
    }

    public function test_a_posted_entry_cannot_be_edited(): void
    {
        $entry = $this->poster->post(
            JournalDraft::for('Original', 'test')
                ->debit(DefaultChartOfAccounts::CASH, $this->eur(10000))
                ->credit(DefaultChartOfAccounts::ACCOMMODATION_REVENUE, $this->eur(10000)),
        );

        $this->expectException(\RuntimeException::class);

        $entry->forceFill(['description' => 'Rewritten history'])->save();
    }

    public function test_a_correction_is_a_reversal_that_keeps_the_original(): void
    {
        $original = $this->poster->post(
            JournalDraft::for('Mistaken charge', 'test')
                ->debit(DefaultChartOfAccounts::CASH, $this->eur(10000))
                ->credit(DefaultChartOfAccounts::ACCOMMODATION_REVENUE, $this->eur(10000)),
        );

        $reversal = $this->poster->reverse($original, 'Charged the wrong booking');

        $this->assertSame(JournalEntry::STATUS_POSTED, $reversal->status);
        $this->assertSame($original->getKey(), $reversal->reverses_entry_id);

        // The original keeps its lines untouched and simply points forward.
        $original->refresh();
        $this->assertSame(JournalEntry::STATUS_REVERSED, $original->status);
        $this->assertSame($reversal->getKey(), $original->reversed_by_entry_id);
        $this->assertSame(2, $original->lines()->count());

        // Sides swapped: that is the whole of what a reversal is.
        $cashLine = $reversal->lines()
            ->whereRelation('account', 'system_key', DefaultChartOfAccounts::CASH)
            ->first();

        $this->assertSame(0, (int) $cashLine->debit);
        $this->assertSame(10000, (int) $cashLine->credit);

        // And the two together net to nothing.
        $this->assertTrue($this->poster->trialBalance()->isZero());
        $this->assertTrue(
            $this->poster->balanceOf($this->poster->account(DefaultChartOfAccounts::CASH))->isZero(),
        );
    }

    public function test_an_entry_cannot_be_reversed_twice(): void
    {
        $entry = $this->poster->post(
            JournalDraft::for('Once', 'test')
                ->debit(DefaultChartOfAccounts::CASH, $this->eur(1000))
                ->credit(DefaultChartOfAccounts::ACCOMMODATION_REVENUE, $this->eur(1000)),
        );

        $this->poster->reverse($entry);

        $this->expectException(\RuntimeException::class);

        $this->poster->reverse($entry->fresh());
    }

    public function test_a_reversal_is_dated_when_the_correction_was_made(): void
    {
        $entry = $this->poster->post(
            JournalDraft::for('Last month', 'test', CarbonImmutable::today()->subMonth())
                ->debit(DefaultChartOfAccounts::CASH, $this->eur(1000))
                ->credit(DefaultChartOfAccounts::ACCOMMODATION_REVENUE, $this->eur(1000)),
        );

        $reversal = $this->poster->reverse($entry);

        // Backdating it would silently change a period somebody has already
        // reported on.
        $this->assertSame(
            CarbonImmutable::today()->toDateString(),
            $reversal->entry_date->toDateString(),
        );
    }

    public function test_balances_read_in_the_direction_the_account_normally_carries(): void
    {
        $this->poster->post(
            JournalDraft::for('Revenue earned', 'test')
                ->debit(DefaultChartOfAccounts::ACCOUNTS_RECEIVABLE, $this->eur(25000))
                ->credit(DefaultChartOfAccounts::ACCOMMODATION_REVENUE, $this->eur(25000)),
        );

        $receivable = $this->poster->balanceOf(
            $this->poster->account(DefaultChartOfAccounts::ACCOUNTS_RECEIVABLE),
        );

        $revenue = $this->poster->balanceOf(
            $this->poster->account(DefaultChartOfAccounts::ACCOMMODATION_REVENUE),
        );

        // An asset with more debits reads positive; so does a revenue account
        // with more credits. Reporting raw debit-minus-credit would make half
        // the balance sheet negative and every reader do the arithmetic in
        // their head.
        $this->assertSame(25000, $receivable->minorUnits);
        $this->assertSame(25000, $revenue->minorUnits);
    }

    public function test_a_draft_entry_is_not_counted_until_it_is_posted(): void
    {
        $draft = $this->poster->draft(
            JournalDraft::for('Awaiting review', 'manual')
                ->debit(DefaultChartOfAccounts::MAINTENANCE_EXPENSE, $this->eur(8000))
                ->credit(DefaultChartOfAccounts::ACCOUNTS_PAYABLE, $this->eur(8000)),
        );

        $this->assertSame(JournalEntry::STATUS_DRAFT, $draft->status);
        $this->assertTrue(
            $this->poster->balanceOf($this->poster->account(DefaultChartOfAccounts::MAINTENANCE_EXPENSE))->isZero(),
        );

        $this->poster->postDraft($draft);

        $this->assertSame(
            8000,
            $this->poster->balanceOf($this->poster->account(DefaultChartOfAccounts::MAINTENANCE_EXPENSE))->minorUnits,
        );
    }

    public function test_the_database_refuses_a_line_that_is_both_a_debit_and_a_credit(): void
    {
        $entry = $this->poster->post(
            JournalDraft::for('Valid', 'test')
                ->debit(DefaultChartOfAccounts::CASH, $this->eur(1000))
                ->credit(DefaultChartOfAccounts::ACCOMMODATION_REVENUE, $this->eur(1000)),
        );

        // A second line of defence that survives a bug in the poster: the
        // constraint lives in PostgreSQL, not in PHP.
        $this->expectException(QueryException::class);

        DB::table('journal_lines')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $this->organization->getKey(),
            'journal_entry_id' => $entry->getKey(),
            'ledger_account_id' => $this->poster->account(DefaultChartOfAccounts::CASH)->getKey(),
            'debit' => 500,
            'credit' => 500,
            'currency' => 'EUR',
            'base_currency' => 'EUR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_attribution_flows_from_the_entry_onto_every_line(): void
    {
        $property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
        ]);

        $entry = $this->poster->post(
            JournalDraft::for('Revenue for a property', 'test', propertyId: $property->getKey())
                ->debit(DefaultChartOfAccounts::ACCOUNTS_RECEIVABLE, $this->eur(10000))
                ->credit(DefaultChartOfAccounts::ACCOMMODATION_REVENUE, $this->eur(10000)),
        );

        // Owner statements and property P&L are derived straight from the
        // ledger, so a line without attribution is a line nobody can bill.
        foreach ($entry->lines as $line) {
            $this->assertSame($property->getKey(), $line->property_id);
        }
    }

    public function test_the_trial_balance_is_zero_after_arbitrary_activity(): void
    {
        foreach ([12500, 8000, 3300, 99, 1] as $amount) {
            $this->poster->post(
                JournalDraft::for('Activity', 'test')
                    ->debit(DefaultChartOfAccounts::CASH, $this->eur($amount))
                    ->credit(DefaultChartOfAccounts::ACCOMMODATION_REVENUE, $this->eur($amount)),
            );
        }

        // If this is ever non-zero, something wrote lines without going
        // through the poster.
        $this->assertTrue($this->poster->trialBalance()->isZero());
    }

    public function test_an_unknown_account_key_fails_loudly(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/chart of accounts/');

        $this->poster->account('an_account_nobody_installed');
    }

    private function eur(int $minorUnits): Money
    {
        return Money::of($minorUnits, 'EUR');
    }
}
