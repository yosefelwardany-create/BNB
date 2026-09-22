<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Properties\Models\Property;
use App\Domain\Reports\DataObjects\ReportParameters;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\Services\ReportExporter;
use App\Domain\Reports\Services\ReportRegistry;
use App\Domain\Reports\Services\ReportRunner;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Users\Models\Permission;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Users\Support\RoleRegistry;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * Reporting.
 *
 * Three things are worth protecting here, and none of them is the arithmetic —
 * that lives in the services the reports delegate to.
 *
 *  - Each report has its own permission, and the catalogue shows only what the
 *    caller can open.
 *  - A saved report holds a key, never a query, and a schedule cannot outlive
 *    its author's access.
 *  - The CSV export cannot be turned into an attack on whoever opens it.
 */
class ReportingTest extends TestCase
{
    use RefreshDatabase;

    private ReportRegistry $registry;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = $this->app->make(ReportRegistry::class);

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'cleaning_fee' => 5000,
            'max_occupancy' => 4,
            'name' => 'Casa das Flores',
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
    // The reports themselves
    // ------------------------------------------------------------------

    public function test_every_registered_report_runs_and_describes_itself(): void
    {
        $this->book(2, 5);

        $parameters = ReportParameters::fromArray([
            'from' => CarbonImmutable::today()->toDateString(),
            'to' => CarbonImmutable::today()->addDays(29)->toDateString(),
        ]);

        foreach ($this->registry->all() as $report) {
            $result = $report->run($parameters);

            // A report that returns rows without saying what its columns mean
            // is one only its author can display.
            $this->assertNotEmpty($report->columns(), $report->key());
            $this->assertNotEmpty($report->name(), $report->key());
            $this->assertNotEmpty($report->permission(), $report->key());

            foreach ($result->rows as $row) {
                foreach ($report->columns() as $column) {
                    $this->assertArrayHasKey(
                        $column['key'],
                        $row,
                        sprintf('%s is missing the %s column', $report->key(), $column['key']),
                    );
                }
            }
        }
    }

    public function test_the_occupancy_report_matches_the_dashboard(): void
    {
        $this->book(2, 5);

        $result = $this->registry->make('occupancy')->run(ReportParameters::fromArray([
            'from' => CarbonImmutable::today()->toDateString(),
            'to' => CarbonImmutable::today()->addDays(9)->toDateString(),
        ]));

        // Delegates to the same analytics the dashboard uses, because two
        // implementations of "what is occupancy" is two numbers and the one on
        // the report is the one somebody takes to a meeting.
        $this->assertSame(3, $result->totals['nights_sold']);
        $this->assertSame(10, $result->totals['nights_available']);
        $this->assertNotEmpty($result->notes);
    }

    public function test_the_reconciliation_report_flags_simulated_money_loudly(): void
    {
        $reservation = $this->book(2, 5);

        $this->app->make(PaymentService::class)->charge(
            Money::of(20000, 'EUR'),
            $reservation,
        );

        $result = $this->registry->make('payment_reconciliation')->run(ReportParameters::fromArray([
            'from' => CarbonImmutable::today()->toDateString(),
            'to' => CarbonImmutable::today()->toDateString(),
        ]));

        $this->assertCount(1, $result->rows);
        $this->assertTrue($result->rows[0]['is_simulated']);

        // A reconciliation report that quietly includes simulated money is a
        // reconciliation report that cannot be trusted at all.
        $this->assertNotEmpty(array_filter(
            $result->notes,
            fn (string $note): bool => str_contains($note, 'simulated'),
        ));

        $this->assertSame(20000, $result->meta['simulated_gross']['amount']);
    }

    public function test_the_reconciliation_report_excludes_channel_collected_money(): void
    {
        $reservation = $this->book(2, 5);

        $this->app->make(PaymentService::class)->recordExternalPayment(
            Money::of(30000, 'EUR'),
            $reservation,
            ['is_collected_by_us' => false],
        );

        $result = $this->registry->make('payment_reconciliation')->run(ReportParameters::fromArray([
            'from' => CarbonImmutable::today()->toDateString(),
            'to' => CarbonImmutable::today()->toDateString(),
        ]));

        // Real revenue that never reached this bank account. Including it
        // would have somebody hunting a deposit that was never going to
        // arrive.
        $this->assertSame(0, $result->count());
    }

    public function test_the_arrivals_report_excludes_cancelled_bookings(): void
    {
        $arriving = $this->book(3, 6);
        $cancelled = $this->book(10, 13);

        $cancelled->forceFill(['status' => ReservationStatus::Cancelled->value])->save();

        $result = $this->registry->make('arrivals_departures')->run(ReportParameters::fromArray([
            'from' => CarbonImmutable::today()->toDateString(),
            'to' => CarbonImmutable::today()->addDays(20)->toDateString(),
        ]));

        $codes = array_column($result->rows, 'confirmation_code');

        $this->assertContains($arriving->confirmation_code, $codes);
        $this->assertNotContains($cancelled->confirmation_code, $codes);

        // An arrival and a departure for the same stay.
        $this->assertSame(1, $result->meta['arrivals']);
        $this->assertSame(1, $result->meta['departures']);
    }

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------

    public function test_a_csv_cell_cannot_become_a_formula(): void
    {
        // A property whose name begins with `=` is a plausible typo and a
        // remote code execution against whoever opens the spreadsheet.
        $this->property->forceFill(['name' => '=cmd|calc'])->save();

        $this->book(3, 6);

        $report = $this->registry->make('arrivals_departures');
        $result = $report->run(ReportParameters::fromArray([
            'from' => CarbonImmutable::today()->toDateString(),
            'to' => CarbonImmutable::today()->addDays(20)->toDateString(),
        ]));

        $csv = $this->app->make(ReportExporter::class)->toCsv($report, $result);

        // Neutralised, not stripped: it is somebody's data, and quietly
        // mangling it is its own bug.
        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringNotContainsString(',=cmd|calc', $csv);
    }

    public function test_money_exports_as_a_number_a_spreadsheet_can_add_up(): void
    {
        $this->book(2, 5);

        $report = $this->registry->make('occupancy');
        $result = $report->run(ReportParameters::fromArray([
            'from' => CarbonImmutable::today()->toDateString(),
            'to' => CarbonImmutable::today()->addDays(9)->toDateString(),
        ]));

        $csv = $this->app->make(ReportExporter::class)->toCsv($report, $result);

        // The currency belongs in the header, stated once, rather than in
        // every cell where a spreadsheet would refuse to sum it.
        $this->assertStringContainsString('Room revenue (EUR)', $csv);
        $this->assertStringNotContainsString('"minor_units"', $csv);
        $this->assertStringNotContainsString('{"amount"', $csv);
    }

    public function test_the_export_endpoint_returns_a_file(): void
    {
        $this->book(2, 5);

        $response = $this->actingAsUser($this->admin, $this->organization)
            ->get('/api/v1/reports/occupancy/export?period=this_month');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString('attachment;', $response->headers->get('content-disposition'));

        // A BOM, so Excel opens UTF-8 as UTF-8 rather than as whatever the
        // machine's locale happens to be.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
    }

    // ------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------

    public function test_the_catalogue_shows_only_what_the_caller_can_run(): void
    {
        $user = $this->userWithPermissions(['reports.view', 'tasks.view']);

        $response = $this->actingAsUser($user, $this->organization)
            ->getJson('/api/v1/reports')
            ->assertOk();

        $keys = collect($response->json('data'))->pluck('key');

        // A list of reports somebody cannot open is an invitation to ask why.
        $this->assertTrue($keys->contains('housekeeping'));
        $this->assertFalse($keys->contains('owner_profit_and_loss'));
        $this->assertFalse($keys->contains('tax_liability'));
    }

    public function test_running_a_report_needs_that_reports_own_permission(): void
    {
        $user = $this->userWithPermissions(['reports.view', 'tasks.view']);

        $this->actingAsUser($user, $this->organization)
            ->getJson('/api/v1/reports/housekeeping?period=this_month')
            ->assertOk();

        // An occupancy report and an owner profit-and-loss are not the same
        // disclosure, and a single reports.view would make them so.
        $this->actingAsUser($user, $this->organization)
            ->getJson('/api/v1/reports/owner_profit_and_loss?period=this_month')
            ->assertForbidden();
    }

    public function test_an_unknown_report_is_a_404_not_a_500(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->getJson('/api/v1/reports/definitely_not_a_report')
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Saved and scheduled reports
    // ------------------------------------------------------------------

    public function test_a_saved_report_stores_a_key_and_parameters_never_a_query(): void
    {
        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/reports/saved', [
                'name' => 'Monthly occupancy',
                'report_key' => 'occupancy',
                'parameters' => ['period' => 'last_month'],
            ])
            ->assertCreated();

        $saved = SavedReport::query()->findOrFail($response->json('data.id'));

        $this->assertSame('occupancy', $saved->report_key);
        $this->assertSame(['period' => 'last_month'], $saved->parameters);
    }

    public function test_a_relative_period_is_resolved_when_the_report_runs(): void
    {
        $saved = SavedReport::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Last month',
            'report_key' => 'occupancy',
            'parameters' => ['period' => 'last_month'],
            'created_by_id' => $this->admin->getKey(),
        ]);

        $parameters = $saved->parameters();

        // Not frozen at save time: that is how a scheduled monthly report ends
        // up emailing the same January figures every month for a year.
        $this->assertSame(
            CarbonImmutable::today()->subMonth()->startOfMonth()->toDateString(),
            $parameters->from->toDateString(),
        );
    }

    public function test_a_report_cannot_be_saved_by_somebody_who_cannot_run_it(): void
    {
        $user = $this->userWithPermissions(['reports.view', 'tasks.view']);

        // Saving a report you cannot run would be a way to have somebody else
        // run it for you.
        $this->actingAsUser($user, $this->organization)
            ->postJson('/api/v1/reports/saved', [
                'name' => 'Owner figures',
                'report_key' => 'owner_profit_and_loss',
            ])
            ->assertForbidden();
    }

    public function test_a_schedule_with_no_recipients_is_refused(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/reports/saved', [
                'name' => 'Nightly',
                'report_key' => 'occupancy',
                'schedule_cron' => '0 8 * * *',
            ])
            ->assertStatus(422);
    }

    public function test_a_malformed_schedule_is_refused_on_the_way_in(): void
    {
        $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/reports/saved', [
                'name' => 'Broken',
                'report_key' => 'occupancy',
                'schedule_cron' => 'every tuesday-ish',
                'recipients' => ['ops@example.test'],
            ])
            ->assertStatus(422);
    }

    public function test_a_valid_schedule_is_given_a_next_run_time(): void
    {
        $response = $this->actingAsUser($this->admin, $this->organization)
            ->postJson('/api/v1/reports/saved', [
                'name' => 'Monday morning',
                'report_key' => 'occupancy',
                'schedule_cron' => '0 8 * * 1',
                'schedule_timezone' => 'Europe/Lisbon',
                'recipients' => ['ops@example.test'],
            ])
            ->assertCreated();

        $this->assertNotNull($response->json('data.next_run_at'));
        $this->assertTrue($response->json('data.is_scheduled'));
    }

    public function test_a_saved_report_is_private_until_it_is_shared(): void
    {
        $colleague = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        $mine = SavedReport::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'My working view',
            'report_key' => 'occupancy',
            'created_by_id' => $this->admin->getKey(),
        ]);

        $this->actingAsUser($colleague, $this->organization)
            ->getJson("/api/v1/reports/saved/{$mine->getKey()}")
            ->assertNotFound();

        $mine->forceFill(['is_shared' => true])->save();

        $this->actingAsUser($colleague, $this->organization)
            ->getJson("/api/v1/reports/saved/{$mine->getKey()}")
            ->assertOk();
    }

    public function test_sharing_a_report_does_not_hand_over_control_of_it(): void
    {
        $colleague = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        $mine = SavedReport::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Shared view',
            'report_key' => 'occupancy',
            'is_shared' => true,
            'created_by_id' => $this->admin->getKey(),
        ]);

        // A colleague who can read somebody's saved view must not be able to
        // repoint its schedule at their own recipients.
        $this->actingAsUser($colleague, $this->organization)
            ->patchJson("/api/v1/reports/saved/{$mine->getKey()}", [
                'recipients' => ['elsewhere@example.test'],
                'schedule_cron' => '0 * * * *',
            ])
            ->assertForbidden();
    }

    public function test_a_schedule_stops_working_when_its_author_loses_access(): void
    {
        $user = $this->userWithPermissions(['reports.view', 'owner_statements.view', 'reports.schedule']);

        $saved = SavedReport::query()->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Owner figures',
            'report_key' => 'owner_profit_and_loss',
            'created_by_id' => $user->getKey(),
        ]);

        $runner = $this->app->make(ReportRunner::class);

        // Works while they hold the permission...
        $this->assertNotEmpty($runner->runSaved($saved->fresh()));

        // ...and stops the moment they do not. Somebody moved off the finance
        // team should stop receiving the finance report, not keep receiving it
        // because they once set one up.
        $this->stripPermissions($user);

        $this->expectException(AccessDeniedHttpException::class);

        $runner->runSaved($saved->fresh());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param  list<string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::query()->create([
            'organization_id' => $this->organization->getKey(),
            'slug' => 'test-role-'.Str::lower(Str::random(8)),
            'name' => 'Test role',
        ]);

        $role->permissions()->sync(
            Permission::query()->whereIn('name', $permissions)->pluck('id'),
        );

        $user = $this->createUser($this->organization, [$role->slug]);

        $this->app->make(AccessControl::class)->flushMemo();

        return $user->fresh();
    }

    private function stripPermissions(User $user): void
    {
        $membership = $this->membershipOf($user, $this->organization);

        foreach ($membership->roles as $role) {
            $role->permissions()->sync([]);
        }

        // Emptying a role touches no membership, so the organization-wide
        // invalidation is what makes the change take effect — exactly as a
        // role editor in the product would have to call it.
        $this->app->make(AccessControl::class)->flushOrganization($this->organization);
    }

    private function book(int $inDays, int $outDays): Reservation
    {
        return $this->app->make(ReservationService::class)->create(new ReservationRequest(
            listing: $this->listing,
            checkIn: CarbonImmutable::today()->addDays($inDays),
            checkOut: CarbonImmutable::today()->addDays($outDays),
            adults: 2,
            status: ReservationStatus::Confirmed,
            guestAttributes: [
                'first_name' => 'Ana',
                'last_name' => 'Costa',
                'email' => 'guest-'.uniqid().'@example.test',
            ],
            bookedAt: CarbonImmutable::today(),
        ));
    }
}
