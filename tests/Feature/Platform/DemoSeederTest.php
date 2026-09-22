<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Integrations\Registries\ChannelAdapterRegistry;
use App\Domain\Listings\Models\Listing;
use App\Domain\Locks\Models\AccessCode;
use App\Domain\Organization\Models\Organization;
use App\Domain\OwnerAccounting\Models\OwnerStatement;
use App\Domain\OwnerAccounting\Models\OwnerStatementLine;
use App\Domain\Payments\Models\Payment;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The demo portfolio.
 *
 * A demo is a claim about what the product does, and an untested one rots
 * quietly: a service signature changes, the seeder starts skipping half its
 * bookings, and the next person to run it sees an empty calendar and concludes
 * the platform does not work.
 *
 * So the seed is held to the same standard as any other data in the system.
 * These do not check that the demo is pretty. They check that it is true: that
 * the ledger balances, that statements add up, that nothing is presented as a
 * live integration, and that the interesting states an operator is meant to
 * find are actually there.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The seeder writes real image files, because publishing a listing
        // really requires a photograph. They go to a fake disk here so a test
        // run does not litter storage.
        Storage::fake(config('filesystems.default'));

        // The test queue is synchronous, so the listeners the seeder relies on
        // (turnover cleans, door codes) run inline rather than waiting for the
        // worker the seeder would otherwise drain.
        $this->seed(DemoSeeder::class);

        $this->app->make(TenantContext::class)->set(
            Organization::query()->withoutGlobalScopes()->where('slug', 'demo-hospitality-group')->firstOrFail(),
        );
    }

    public function test_it_creates_one_organization_with_a_full_staff(): void
    {
        $organization = Organization::query()
            ->withoutGlobalScopes()
            ->where('slug', 'demo-hospitality-group')
            ->firstOrFail();

        $this->assertSame('EUR', $organization->base_currency);

        // Seven people, each with a different role. A demo with one
        // administrator demonstrates nothing about authorisation.
        $this->assertSame(7, DB::table('memberships')
            ->where('organization_id', $organization->getKey())
            ->count());
    }

    public function test_every_listing_is_published_and_therefore_bookable(): void
    {
        $listings = Listing::query()->with(['photos.propertyPhoto', 'property.photos'])->get();

        $this->assertCount(4, $listings);

        foreach ($listings as $listing) {
            // Publication is gated on a photograph, a description and a
            // positive rate. Published means the gate really opened.
            $this->assertNotNull($listing->published_at, $listing->name.' is not published');
            $this->assertNotSame([], $listing->effectivePhotos());
        }
    }

    public function test_the_book_of_business_spans_past_present_and_future(): void
    {
        $reservations = Reservation::query()->get();

        $this->assertGreaterThanOrEqual(12, $reservations->count());

        $inHouse = $reservations->filter(fn (Reservation $r): bool => $r->status->value === 'checked_in');
        $upcoming = $reservations->filter(fn (Reservation $r): bool => $r->check_in_date->isFuture());
        $past = $reservations->filter(fn (Reservation $r): bool => $r->status->value === 'checked_out');
        $cancelled = $reservations->filter(fn (Reservation $r): bool => $r->status->isCancelled());

        // Each of these drives a different screen. Losing any one of them
        // makes the demo quietly less useful without making it fail.
        $this->assertNotEmpty($inHouse, 'no guests are in house today');
        $this->assertNotEmpty($upcoming, 'nothing is arriving');
        $this->assertNotEmpty($past, 'there is no history to report on');
        $this->assertNotEmpty($cancelled, 'the cancelled state is not represented');
    }

    public function test_no_two_bookings_overlap_on_the_same_property(): void
    {
        $byProperty = Reservation::query()
            ->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
            ->get()
            ->groupBy('property_id');

        foreach ($byProperty as $propertyId => $reservations) {
            $sorted = $reservations->sortBy('check_in_date')->values();

            for ($i = 1; $i < $sorted->count(); $i++) {
                $this->assertTrue(
                    $sorted[$i]->check_in_date >= $sorted[$i - 1]->check_out_date,
                    sprintf(
                        'Property %s is double-booked: %s overlaps %s.',
                        $propertyId,
                        $sorted[$i]->confirmation_code,
                        $sorted[$i - 1]->confirmation_code,
                    ),
                );
            }
        }
    }

    public function test_the_ledger_balances(): void
    {
        $totals = DB::table('journal_lines')
            ->selectRaw('sum(debit) as debits, sum(credit) as credits')
            ->first();

        $this->assertNotNull($totals);

        // The one invariant a double-entry system has. If this fails, some
        // posting rule is wrong and every financial screen is wrong with it.
        $this->assertSame(
            (int) $totals->debits,
            (int) $totals->credits,
            'The demo ledger does not balance.',
        );

        $this->assertGreaterThan(0, (int) $totals->debits, 'Nothing was posted at all.');
    }

    public function test_each_statement_adds_up_to_its_own_lines(): void
    {
        $statements = OwnerStatement::query()->with('lines')->get();

        $this->assertNotEmpty($statements);

        foreach ($statements as $statement) {
            $sum = $statement->lines->sum(fn (OwnerStatementLine $line): int => (int) $line->amount);

            // The first thing an owner does is add up the column.
            $this->assertSame(
                (int) $statement->closing_balance,
                $sum,
                sprintf('Statement %s does not add up.', $statement->reference),
            );
        }
    }

    public function test_it_leaves_work_for_an_operator_to_find(): void
    {
        // A demo where every queue is empty demonstrates nothing about the
        // queues. Each of these is deliberately left outstanding.
        $this->assertTrue(
            DB::table('tasks')->where('status', '!=', 'completed')->exists(),
            'there is no outstanding work on the board',
        );

        $this->assertTrue(
            DB::table('expenses')->where('status', 'draft')->exists(),
            'nothing is waiting for expense approval',
        );

        $this->assertTrue(
            OwnerStatement::query()->where('status', OwnerStatement::STATUS_DRAFT)->exists(),
            'every statement is already approved, so the freeze cannot be seen',
        );

        $this->assertTrue(
            DB::table('reviews')->whereNull('response')->exists(),
            'every review is answered',
        );

        $this->assertTrue(
            DB::table('conversations')->where('last_message_direction', 'inbound')->exists(),
            'no guest is waiting for a reply',
        );
    }

    public function test_it_generates_the_turnover_cleans_the_bookings_imply(): void
    {
        $this->assertGreaterThan(
            0,
            DB::table('tasks')->where('generated_by', 'turnover_scheduler')->count(),
            'no turnover cleans were generated for the demo bookings',
        );
    }

    public function test_nothing_claims_to_be_a_live_integration(): void
    {
        // The rule this product cannot afford to break. Every adapter in the
        // demo is a local simulation, and every record produced by one says
        // so in a field the interface reads.
        foreach (AccessCode::query()->get() as $code) {
            $this->assertTrue(
                (bool) $code->is_simulated,
                'An access code is not marked simulated, implying it opens a real door.',
            );
        }

        $this->assertTrue(
            DB::table('smart_locks')->where('is_simulated', false)->doesntExist(),
            'A demo lock is presented as real hardware.',
        );

        foreach (Payment::query()->get() as $payment) {
            // A payment is honest in one of two ways: it was simulated by the
            // local provider, or the money really moved and somebody else is
            // holding it. What must never appear is a payment that is neither.
            $this->assertTrue(
                (bool) $payment->is_simulated || ! $payment->is_collected_by_us,
                sprintf(
                    'Payment %s claims real money reached our account.',
                    $payment->reference,
                ),
            );
        }

        foreach (ChannelAccount::query()->get() as $account) {
            $adapter = app(ChannelAdapterRegistry::class)
                ->make($account->channel);

            $this->assertFalse(
                $adapter->isLive(),
                'A demo channel account is backed by a live adapter.',
            );
        }
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $before = [
            'reservations' => DB::table('reservations')->count(),
            'statements' => DB::table('owner_statements')->count(),
            'payments' => DB::table('payments')->count(),
        ];

        // A second run must not duplicate a single booking or statement. The
        // seeder refuses rather than deleting anything to make room.
        $this->seed(DemoSeeder::class);

        $this->assertSame($before, [
            'reservations' => DB::table('reservations')->count(),
            'statements' => DB::table('owner_statements')->count(),
            'payments' => DB::table('payments')->count(),
        ]);
    }
}
