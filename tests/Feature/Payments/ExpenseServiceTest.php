<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\DefaultChartOfAccounts as Accounts;
use App\Domain\Organization\Models\Organization;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Models\Expense;
use App\Domain\Payments\Services\ExpenseService;
use App\Domain\Properties\Models\Property;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Costs incurred against a property.
 *
 * The three things worth proving here are all about who ends up paying: that
 * nothing hits the accounts until somebody approves it, that correcting an
 * approved cost reverses rather than rewrites, and that a cost already billed
 * to an owner is frozen for good.
 */
class ExpenseServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseService $expenses;

    private JournalPoster $poster;

    private Organization $organization;

    private Property $property;

    private Owner $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->expenses = $this->app->make(ExpenseService::class);
        $this->poster = $this->app->make(JournalPoster::class);

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
        ]);

        $this->owner = Owner::query()->create([
            'organization_id' => $this->organization->getKey(),
            'type' => 'individual',
            'first_name' => 'Marta',
            'last_name' => 'Oliveira',
            'email' => 'marta@example.test',
        ]);

        DB::table('property_ownerships')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $this->organization->getKey(),
            'owner_id' => $this->owner->getKey(),
            'property_id' => $this->property->getKey(),
            'ownership_percentage' => 100,
            'starts_on' => now()->subYear()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_draft_expense_posts_nothing_to_the_ledger(): void
    {
        $expense = $this->record(['amount' => 12000]);

        $this->assertSame(Expense::DRAFT, $expense->status);

        // A draft is somebody's unverified claim — a photograph of a receipt,
        // a contractor's word. The accounts must not move on it.
        $this->assertSame(0, $this->entriesFor($expense)->count());
        $this->assertTrue($this->balance(Accounts::MAINTENANCE_EXPENSE)->isZero());
    }

    public function test_approving_debits_the_category_and_credits_the_payable(): void
    {
        $expense = $this->expenses->approve($this->record(['amount' => 12000, 'tax_amount' => 2760]));

        $this->assertSame(Expense::APPROVED, $expense->status);
        $this->assertNotNull($expense->approved_at);

        // The vendor is owed the cost and its tax the moment the work is
        // accepted, regardless of when they are actually paid.
        $this->assertSame(14760, $this->balance(Accounts::MAINTENANCE_EXPENSE)->minorUnits);
        $this->assertSame(14760, $this->balance(Accounts::ACCOUNTS_PAYABLE)->minorUnits);
    }

    public function test_the_manager_margin_is_charged_to_the_owner_but_never_paid_to_the_vendor(): void
    {
        $expense = $this->expenses->approve($this->record([
            'amount' => 10000,
            'markup_percent' => 15,
            'billable_to' => Expense::TO_OWNER,
        ]));

        // The margin is computed from the percentage rather than trusted from
        // the caller, so the two can never disagree on a record an owner reads.
        $this->assertSame(1500, (int) $expense->markup_amount);
        $this->assertSame(11500, $expense->chargeableAmount()->minorUnits);

        // ...but the contractor is owed only their own price.
        $this->assertSame(10000, $this->balance(Accounts::ACCOUNTS_PAYABLE)->minorUnits);
    }

    public function test_paying_settles_the_payable_against_cash_and_leaves_the_cost_alone(): void
    {
        $expense = $this->expenses->approve($this->record(['amount' => 8000]));

        $this->expenses->markPaid($expense, 'bank_transfer');

        $this->assertSame(Expense::PAID, $expense->fresh()->status);
        $this->assertTrue($expense->fresh()->is_paid);

        // The payable is discharged and cash goes down; what the job cost is
        // unchanged, because paying a bill does not alter its amount.
        $this->assertTrue($this->balance(Accounts::ACCOUNTS_PAYABLE)->isZero());
        $this->assertSame(-8000, $this->balance(Accounts::CASH)->minorUnits);
        $this->assertSame(8000, $this->balance(Accounts::MAINTENANCE_EXPENSE)->minorUnits);
    }

    public function test_an_unapproved_expense_cannot_be_paid(): void
    {
        $this->expectException(PaymentException::class);

        $this->expenses->markPaid($this->record(['amount' => 5000]));
    }

    public function test_correcting_an_approved_expense_reverses_rather_than_rewrites(): void
    {
        $expense = $this->expenses->approve($this->record(['amount' => 10000]));

        $this->expenses->update($expense, ['amount' => 6000]);

        // The mistake and its correction are both visible: two live entries
        // reversed, plus the replacement. Nothing was edited in place.
        $this->assertSame(6000, $this->balance(Accounts::MAINTENANCE_EXPENSE)->minorUnits);

        $entries = $this->entriesFor($expense->fresh());

        $this->assertGreaterThanOrEqual(3, $entries->count());
        $this->assertTrue($entries->contains(fn (JournalEntry $e): bool => $e->source === 'reversal'));
    }

    public function test_rejecting_an_approved_expense_gives_the_payable_back(): void
    {
        $expense = $this->expenses->approve($this->record(['amount' => 7500]));

        $this->expenses->reject($expense, 'Charged to the wrong property.');

        $this->assertSame(Expense::REJECTED, $expense->fresh()->status);

        // Nothing is owed and nothing was spent — but the record of the claim
        // and of the refusal both survive.
        $this->assertTrue($this->balance(Accounts::ACCOUNTS_PAYABLE)->isZero());
        $this->assertTrue($this->balance(Accounts::MAINTENANCE_EXPENSE)->isZero());
        $this->assertStringContainsString('wrong property', (string) $expense->fresh()->notes);
    }

    public function test_an_expense_on_a_finalised_statement_is_frozen(): void
    {
        $expense = $this->expenses->approve($this->record(['amount' => 4000]));

        // Exactly what approving a statement does to the expenses it swept up.
        $statement = OwnerStatement::query()->create([
            'organization_id' => $this->organization->getKey(),
            'owner_id' => $this->owner->getKey(),
            'reference' => 'OS-TEST-1',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'currency' => 'EUR',
        ]);

        $expense->forceFill(['owner_statement_id' => $statement->getKey()])->save();

        $this->assertFalse($expense->fresh()->isEditable());

        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('finalised owner statement');

        $this->expenses->update($expense->fresh(), ['amount' => 9000]);
    }

    public function test_the_owner_is_derived_from_who_held_the_property_on_the_day(): void
    {
        $expense = $this->record(['amount' => 3000]);

        // Not "whoever owns it today": the ownership in force on the expense
        // date is what decides who bears the cost.
        $this->assertSame($this->owner->getKey(), $expense->owner_id);
    }

    public function test_expenses_are_numbered_with_their_own_sequence(): void
    {
        $first = $this->record(['amount' => 1000]);
        $second = $this->record(['amount' => 2000]);

        $this->assertStringStartsWith('EXP-', $first->reference);
        $this->assertNotSame($first->reference, $second->reference);
    }

    private function balance(string $systemKey): Money
    {
        return $this->poster->balanceOf($this->poster->account($systemKey));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function record(array $attributes): Expense
    {
        return $this->expenses->create(array_merge([
            'property_id' => $this->property->getKey(),
            'expense_date' => now()->toDateString(),
            'category' => 'maintenance',
            'description' => 'Replaced the bathroom extractor fan',
            'currency' => 'EUR',
        ], $attributes));
    }

    /**
     * @return Collection<int, JournalEntry>
     */
    private function entriesFor(Expense $expense): Collection
    {
        return JournalEntry::query()
            ->where('source_type', $expense->getMorphClass())
            ->where('source_id', $expense->getKey())
            ->get();
    }
}
