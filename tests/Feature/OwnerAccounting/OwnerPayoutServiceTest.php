<?php

declare(strict_types=1);

namespace Tests\Feature\OwnerAccounting;

use App\Domain\Accounting\Services\JournalPoster;
use App\Domain\Accounting\Support\DefaultChartOfAccounts as Accounts;
use App\Domain\Organization\Models\Organization;
use App\Domain\OwnerAccounting\Exceptions\OwnerStatementException;
use App\Domain\OwnerAccounting\Models\OwnerPayout;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\OwnerAccounting\Services\OwnerPayoutService;
use App\Domain\Owners\Models\Owner;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sending owners their money.
 *
 * The properties that matter are all about not paying twice and not losing
 * track of where money went: raising is idempotent per statement, the ledger
 * moves only when the transfer actually happens, and the destination is frozen
 * onto the record the moment it is used.
 */
class OwnerPayoutServiceTest extends TestCase
{
    use RefreshDatabase;

    private OwnerPayoutService $payouts;

    private JournalPoster $poster;

    private Organization $organization;

    private Owner $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payouts = $this->app->make(OwnerPayoutService::class);
        $this->poster = $this->app->make(JournalPoster::class);

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->owner = Owner::query()->create([
            'organization_id' => $this->organization->getKey(),
            'type' => 'individual',
            'first_name' => 'Henrik',
            'last_name' => 'Sørensen',
            'email' => 'henrik@example.test',
            'payout_currency' => 'EUR',
            'payout_method' => 'bank_transfer',
            'bank_name' => 'Nordea',
            'bank_account_name' => 'Henrik Sørensen',
            'bank_account_number' => '12345678901234',
        ]);
    }

    public function test_raising_a_payout_posts_nothing_until_the_money_moves(): void
    {
        $payout = $this->payouts->create($this->owner, Money::of(50000, 'EUR'));

        $this->assertSame('pending', $payout->status);
        $this->assertStringStartsWith('PO-', $payout->reference);

        // A pending payout is an intention. Debiting the owner payable now
        // would say we had paid them when we had not.
        $this->assertTrue($this->balance(Accounts::OWNER_PAYABLE)->isZero());
        $this->assertTrue($this->balance(Accounts::CASH)->isZero());
    }

    public function test_settling_discharges_the_payable_against_cash(): void
    {
        $payout = $this->payouts->create($this->owner, Money::of(50000, 'EUR'));

        $this->payouts->markPaid($payout, 'SEPA-9931');

        $this->assertTrue($payout->fresh()->isPaid());
        $this->assertSame('SEPA-9931', $payout->fresh()->external_reference);

        $this->assertSame(-50000, $this->balance(Accounts::OWNER_PAYABLE)->minorUnits);
        $this->assertSame(-50000, $this->balance(Accounts::CASH)->minorUnits);
    }

    public function test_the_destination_is_frozen_at_the_moment_of_payment(): void
    {
        $payout = $this->payouts->create($this->owner, Money::of(20000, 'EUR'));

        // Masked on the way in: enough to recognise the account, never enough
        // to use it.
        $this->assertSame('1234', $payout->destination_snapshot['account_last4']);
        $this->assertSame('Nordea', $payout->destination_snapshot['bank_name']);
        $this->assertArrayNotHasKey('account_number', $payout->destination_snapshot);

        // An owner changing bank next year must not rewrite where last year's
        // money went.
        $this->owner->forceFill(['bank_name' => 'Danske', 'bank_account_number' => '99999999'])->save();

        $this->assertSame('Nordea', $payout->fresh()->destination_snapshot['bank_name']);
        $this->assertSame('1234', $payout->fresh()->destination_snapshot['account_last4']);
    }

    public function test_raising_against_a_statement_twice_returns_the_same_payout(): void
    {
        $statement = $this->approvedStatement(75000);

        $first = $this->payouts->fromStatement($statement);
        $second = $this->payouts->fromStatement($statement);

        // A second click, a retried request, a redelivered job: none of them
        // may send the money twice.
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, OwnerPayout::query()->count());
    }

    public function test_a_draft_statement_cannot_be_paid(): void
    {
        $statement = $this->statement(75000);

        $this->expectException(OwnerStatementException::class);
        $this->expectExceptionMessage('approved');

        $this->payouts->fromStatement($statement);
    }

    public function test_a_statement_with_nothing_to_pay_out_is_refused(): void
    {
        $statement = $this->approvedStatement(0);

        $this->expectException(OwnerStatementException::class);
        $this->expectExceptionMessage('nothing to pay out');

        $this->payouts->fromStatement($statement);
    }

    public function test_settling_a_statement_payout_marks_the_statement_paid(): void
    {
        $statement = $this->approvedStatement(30000);

        $this->payouts->markPaid($this->payouts->fromStatement($statement));

        $this->assertSame(OwnerStatement::STATUS_PAID, $statement->fresh()->status);
    }

    public function test_a_failed_transfer_reverses_nothing_because_nothing_was_posted(): void
    {
        $payout = $this->payouts->create($this->owner, Money::of(40000, 'EUR'));

        $this->payouts->markFailed($payout, 'The account has been closed.');

        $this->assertSame('failed', $payout->fresh()->status);
        $this->assertStringContainsString('closed', (string) $payout->fresh()->failure_message);

        // The owner is still owed: nothing left the bank, so nothing comes
        // back out of the ledger either.
        $this->assertTrue($this->balance(Accounts::OWNER_PAYABLE)->isZero());
    }

    public function test_a_sent_payout_cannot_be_cancelled_or_failed(): void
    {
        $payout = $this->payouts->markPaid(
            $this->payouts->create($this->owner, Money::of(10000, 'EUR')),
        );

        $this->expectException(OwnerStatementException::class);

        $this->payouts->cancel($payout);
    }

    public function test_cancelling_frees_the_statement_to_be_paid_again(): void
    {
        $statement = $this->approvedStatement(60000);

        $first = $this->payouts->fromStatement($statement);
        $this->payouts->cancel($first, 'Wrong bank details on file.');

        $second = $this->payouts->fromStatement($statement);

        // A corrected payout, not a duplicate of the cancelled one.
        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame('cancelled', $first->fresh()->status);
    }

    private function balance(string $systemKey): Money
    {
        return $this->poster->balanceOf($this->poster->account($systemKey));
    }

    private function statement(int $payoutAmount): OwnerStatement
    {
        return OwnerStatement::query()->create([
            'organization_id' => $this->organization->getKey(),
            'owner_id' => $this->owner->getKey(),
            'reference' => 'OS-'.Str::random(6),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'currency' => 'EUR',
            'payout_amount' => $payoutAmount,
            'net_due' => $payoutAmount,
            'closing_balance' => $payoutAmount,
        ]);
    }

    private function approvedStatement(int $payoutAmount): OwnerStatement
    {
        $statement = $this->statement($payoutAmount);

        $statement->forceFill([
            'status' => OwnerStatement::STATUS_APPROVED,
            'approved_at' => now(),
        ])->save();

        return $statement->fresh();
    }
}
