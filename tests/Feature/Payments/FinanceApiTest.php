<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Payments\Models\Expense;
use App\Domain\Payments\Services\ExpenseService;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The financial API over HTTP.
 *
 * Beyond the round trips, three things are being protected here:
 *
 *  - The right to charge a card does not confer the right to refund one. They
 *    are not symmetrical mistakes.
 *  - An owner with portal access reaches exactly one owner's statements —
 *    their own — and only the ones actually sent to them.
 *  - Nothing in a response claims a payment was real when it was simulated.
 */
class FinanceApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'cleaning_fee' => 5000,
            'max_occupancy' => 4,
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);

        $this->admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);
    }

    // ------------------------------------------------------------------
    // Payments
    // ------------------------------------------------------------------

    public function test_a_charge_is_taken_and_reported_with_its_provenance(): void
    {
        $reservation = $this->book();

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/payments', [
                'amount' => 35000,
                'currency' => 'EUR',
                'reservation_id' => $reservation->getKey(),
                'description' => 'Deposit',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'captured')
            ->assertJsonPath('data.amount.amount', 35000);

        // The bundled processor is a real local implementation, not a live
        // one, and the response says so rather than letting an operator assume
        // money moved through a bank.
        $this->assertTrue($response->json('data.is_simulated'));

        // The card token never leaves the server.
        $this->assertArrayNotHasKey('instrument_token', $response->json('data'));
    }

    public function test_an_authorisation_can_be_captured_later(): void
    {
        $reservation = $this->book();

        $held = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/payments/hold', [
                'amount' => 40000,
                'reservation_id' => $reservation->getKey(),
            ])->assertCreated();

        $this->assertSame('authorized', $held->json('data.status'));

        $captured = $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/payments/{$held->json('data.id')}/capture", ['amount' => 25000]);

        $captured->assertOk()
            ->assertJsonPath('data.captured_amount.amount', 25000);

        // Partial captures are normal — a guest who shortens their stay — so
        // the rest of the hold is still available.
        $this->assertSame(15000, $captured->json('data.capturable_amount.amount'));
    }

    public function test_money_collected_by_a_channel_is_recorded_without_pretending_we_hold_it(): void
    {
        $reservation = $this->book();

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/payments/external', [
                'amount' => 30000,
                'reservation_id' => $reservation->getKey(),
                'method' => 'channel_collected',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'captured')
            // The guest has paid; we are not holding the money.
            ->assertJsonPath('data.is_collected_by_us', false)
            // And nothing was simulated: it really moved, just not through us.
            ->assertJsonPath('data.is_simulated', false);
    }

    public function test_charging_does_not_confer_the_right_to_refund(): void
    {
        $reservation = $this->book();

        $agent = $this->createUser($this->organization, [RoleRegistry::RESERVATIONS_AGENT]);

        $payment = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/payments', [
                'amount' => 20000,
                'reservation_id' => $reservation->getKey(),
            ])->assertCreated()->json('data.id');

        $this->actingAsUser($agent, $this->organization)
            ->postJson("/api/v1/payments/{$payment}/refund", ['amount' => 5000])
            ->assertForbidden();
    }

    public function test_a_refund_beyond_the_capture_is_refused(): void
    {
        $reservation = $this->book();

        $payment = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/payments', [
                'amount' => 20000,
                'reservation_id' => $reservation->getKey(),
            ])->assertCreated()->json('data.id');

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/payments/{$payment}/refund", ['amount' => 50000])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Instalments
    // ------------------------------------------------------------------

    public function test_a_booking_can_be_given_a_deposit_and_balance_plan(): void
    {
        $reservation = $this->book();

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/reservations/{$reservation->getKey()}/payment-schedule", [
                'deposit_percent' => 25,
                'balance_days_before_arrival' => 7,
            ]);

        $response->assertCreated();

        $amounts = collect($response->json('data'))->sum('amount.amount');

        $this->assertSame((int) $reservation->grand_total, $amounts);
    }

    // ------------------------------------------------------------------
    // Expenses
    // ------------------------------------------------------------------

    public function test_an_expense_moves_through_draft_approval_and_payment(): void
    {
        $created = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/expenses', [
                'property_id' => $this->property->getKey(),
                'expense_date' => now()->toDateString(),
                'category' => 'cleaning',
                'description' => 'Deep clean after a long stay',
                'amount' => 9000,
                'billable_to' => 'owner',
                'markup_percent' => 10,
            ])->assertCreated();

        $id = $created->json('data.id');

        $this->assertSame('draft', $created->json('data.status'));
        $this->assertSame(900, $created->json('data.markup_amount.amount'));
        $this->assertSame(9900, $created->json('data.chargeable_amount.amount'));

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/expenses/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/expenses/{$id}/pay", ['method' => 'bank_transfer'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.is_paid', true);
    }

    public function test_the_expense_summary_splits_costs_by_who_bears_them(): void
    {
        $this->seedExpense(5000, Expense::TO_OWNER, 'cleaning');
        $this->seedExpense(3000, Expense::TO_MANAGER, 'supplies');

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->getJson('/api/v1/expenses/summary?from='.now()->subMonth()->toDateString()
                .'&to='.now()->addMonth()->toDateString());

        $response->assertOk()
            ->assertJsonPath('data.total.amount', 8000)
            ->assertJsonPath('data.by_bearer.owner.amount', 5000)
            ->assertJsonPath('data.by_bearer.manager.amount', 3000);
    }

    // ------------------------------------------------------------------
    // Owner statements
    // ------------------------------------------------------------------

    public function test_an_owner_only_sees_their_own_statements_and_only_once_sent(): void
    {
        $mine = $this->ownerWithPortalLogin('mine@example.test');
        $theirs = $this->owner('theirs@example.test');

        $sent = $this->statementFor($mine['owner'], OwnerStatement::STATUS_SENT);
        $draft = $this->statementFor($mine['owner'], OwnerStatement::STATUS_DRAFT);
        $other = $this->statementFor($theirs, OwnerStatement::STATUS_SENT);

        $response = $this->actingAsUser($mine['user'], $this->organization)
            ->getJson('/api/v1/owner-statements')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($sent->getKey()));

        // A draft is the manager's working figure, not a statement of account.
        $this->assertFalse($ids->contains($draft->getKey()));

        // And their neighbour's revenue is none of their business.
        $this->assertFalse($ids->contains($other->getKey()));

        $this->actingAsUser($mine['user'], $this->organization)
            ->getJson("/api/v1/owner-statements/{$other->getKey()}")
            ->assertForbidden();
    }

    public function test_a_statement_is_voided_rather_than_deleted(): void
    {
        $owner = $this->owner('void@example.test');
        $statement = $this->statementFor($owner, OwnerStatement::STATUS_APPROVED);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/owner-statements/{$statement->getKey()}/void", [
                'reason' => 'Built before the March invoices arrived.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'void');

        // Still there. The owner may be holding a copy, and the system has to
        // be able to say what it said.
        $this->assertDatabaseHas('owner_statements', ['id' => $statement->getKey()]);
    }

    // ------------------------------------------------------------------
    // Payouts
    // ------------------------------------------------------------------

    public function test_a_payout_is_raised_from_a_statement_and_settled_separately(): void
    {
        $owner = $this->owner('payout@example.test');
        $statement = $this->statementFor($owner, OwnerStatement::STATUS_APPROVED, 42000);

        $payout = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/owner-payouts', [
                'owner_statement_id' => $statement->getKey(),
            ])->assertCreated();

        $this->assertSame('pending', $payout->json('data.status'));
        $this->assertSame(42000, $payout->json('data.amount.amount'));

        // Masked: enough to recognise the account, never enough to use it.
        $this->assertArrayNotHasKey('account_number', $payout->json('data.destination') ?? []);

        $this->actingAsUser($this->admin, $this->organization)
            ->postJson("/api/v1/owner-payouts/{$payout->json('data.id')}/settle", [
                'external_reference' => 'SEPA-114',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_paid', true);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function book(int $inDays = 20, int $outDays = 23): Reservation
    {
        return $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $this->listing,
            checkIn: CarbonImmutable::today()->addDays($inDays),
            checkOut: CarbonImmutable::today()->addDays($outDays),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Marta',
                'last_name' => 'Silva',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
            bookedAt: CarbonImmutable::today(),
        ));
    }

    private function owner(string $email): Owner
    {
        return Owner::query()->create([
            'organization_id' => $this->organization->getKey(),
            'type' => 'individual',
            'first_name' => 'Owner',
            'last_name' => ucfirst(explode('@', $email)[0]),
            'email' => $email,
            'payout_currency' => 'EUR',
            'bank_name' => 'Banco Exemplo',
            'bank_account_number' => '55556666777788',
        ]);
    }

    /**
     * @return array{owner: Owner, user: User}
     */
    private function ownerWithPortalLogin(string $email): array
    {
        $owner = $this->owner($email);

        $user = $this->createUser($this->organization, [RoleRegistry::OWNER], ['email' => $email]);

        $owner->forceFill(['user_id' => $user->getKey(), 'portal_enabled' => true])->save();

        return ['owner' => $owner->fresh(), 'user' => $user];
    }

    private function statementFor(Owner $owner, string $status, int $payout = 10000): OwnerStatement
    {
        $statement = OwnerStatement::query()->create([
            'organization_id' => $this->organization->getKey(),
            'owner_id' => $owner->getKey(),
            'reference' => 'OS-'.Str::random(8),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'currency' => 'EUR',
            'payout_amount' => $payout,
            'net_due' => $payout,
            'closing_balance' => $payout,
        ]);

        if ($status !== OwnerStatement::STATUS_DRAFT) {
            $statement->forceFill(['status' => $status, 'approved_at' => now()])->save();
        }

        return $statement->fresh();
    }

    private function seedExpense(int $amount, string $bearer, string $category): Expense
    {
        $expense = $this->app->make(ExpenseService::class)->create([
            'property_id' => $this->property->getKey(),
            'expense_date' => now()->toDateString(),
            'category' => $category,
            'description' => 'Seeded '.$category,
            'amount' => $amount,
            'currency' => 'EUR',
            'billable_to' => $bearer,
        ]);

        return $this->app->make(ExpenseService::class)->approve($expense);
    }
}
