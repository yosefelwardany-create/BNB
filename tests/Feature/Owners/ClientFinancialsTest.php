<?php

declare(strict_types=1);

namespace Tests\Feature\Owners;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Owners\Models\ManagementAgreement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Services\ClientAccounts;
use App\Domain\Owners\Services\ClientFinancials;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The 10% management commission, per property and currency.
 *
 * The arithmetic is simple and the rules around it are not: which nights
 * count, in which currency, at which terms, and what is said when the data
 * does not support a final figure. These build their stays directly from
 * night rows so each rule is exercised on exactly the data it concerns.
 */
class ClientFinancialsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Owner $holder;

    private User $client;

    private CarbonImmutable $from;

    private CarbonImmutable $to;

    protected function setUp(): void
    {
        parent::setUp();

        ['organization' => $this->organization, 'owner' => $this->holder, 'user' => $this->client]
            = $this->createClientOrganization(['base_currency' => 'CAD']);

        $this->from = CarbonImmutable::parse('2026-09-01');
        $this->to = CarbonImmutable::parse('2026-09-30');
    }

    public function test_one_thousand_earns_one_hundred_commission_and_nine_hundred_after(): void
    {
        $property = $this->property('CAD');
        $this->stay($property, '2026-09-10', 4, 25000); // 4 nights × 250.00 = 1,000.00

        $row = $this->rowFor($property);

        $this->assertSame(100000, $row['revenue_before_commission']['amount']);
        $this->assertSame(10000, $row['commission']['amount']);
        $this->assertSame(90000, $row['revenue_after_commission']['amount']);
        $this->assertSame('CAD', $row['revenue_after_commission']['currency']);
        $this->assertEqualsWithDelta(10.0, $row['commission_rate'], 0.0001);
        $this->assertTrue($row['is_final']);
        $this->assertSame([], $row['flags']);
    }

    public function test_commission_is_rounded_once_half_up_on_the_aggregate(): void
    {
        $property = $this->property('CAD');
        // 1,234.56: three nights that add up to 123456 minor units.
        $this->stay($property, '2026-09-10', 3, 41152);

        $row = $this->rowFor($property);

        $this->assertSame(123456, $row['revenue_before_commission']['amount']);
        $this->assertSame(12346, $row['commission']['amount']);      // 12345.6 → 12346
        $this->assertSame(111110, $row['revenue_after_commission']['amount']);

        // The money type itself: 10.05 → 1.01 (half up), 10.04 → 1.00.
        $this->assertSame(101, Money::of(1005, 'CAD')->percentage(10)->minorUnits);
        $this->assertSame(100, Money::of(1004, 'CAD')->percentage(10)->minorUnits);
        // A zero-decimal currency rounds to whole units.
        $this->assertSame(1, Money::of(5, 'JPY')->percentage(10)->minorUnits);
    }

    public function test_currencies_are_never_combined(): void
    {
        $cad = $this->property('CAD');
        $usd = $this->property('USD');
        $this->stay($cad, '2026-09-10', 2, 10000);
        $this->stay($usd, '2026-09-10', 2, 10000);

        $data = $this->financials();

        $this->assertCount(2, $data['properties']);
        $this->assertCount(2, $data['totals_by_currency']);

        $totals = collect($data['totals_by_currency'])->keyBy('currency');
        $this->assertSame(20000, $totals['CAD']['revenue_before_commission']['amount']);
        $this->assertSame(20000, $totals['USD']['revenue_before_commission']['amount']);
        $this->assertArrayNotHasKey('total', $data);
    }

    public function test_a_cancelled_stay_earns_nothing_and_is_flagged(): void
    {
        $property = $this->property('CAD');
        $this->stay($property, '2026-09-10', 2, 10000);
        $this->stay($property, '2026-09-20', 3, 10000, ReservationStatus::Cancelled);

        $row = $this->rowFor($property);

        $this->assertSame(20000, $row['revenue_before_commission']['amount']);
        $this->assertSame(2000, $row['commission']['amount']);
        $this->assertFalse($row['is_final']);
        $this->assertContains('cancellations_present', array_column($row['flags'], 'code'));
    }

    public function test_a_stay_spanning_the_period_boundary_counts_only_its_nights_inside(): void
    {
        $property = $this->property('CAD');
        // 28 Sep → 3 Oct: five nights, three of them in September.
        $this->stay($property, '2026-09-28', 5, 10000);

        $row = $this->rowFor($property);

        $this->assertSame(3, $row['nights_sold']);
        $this->assertSame(30000, $row['revenue_before_commission']['amount']);
        $this->assertSame(3000, $row['commission']['amount']);
    }

    public function test_an_unverified_amount_is_flagged_rather_than_counted_as_zero(): void
    {
        $property = $this->property('CAD');
        $reservation = $this->stay($property, '2026-09-10', 2, 10000);

        DB::table('reservations')->where('id', $reservation->getKey())->update(['accommodation_total' => null]);
        DB::table('reservation_nights')->where('reservation_id', $reservation->getKey())->update(['rate_amount' => null]);

        $row = $this->rowFor($property);

        $this->assertSame(2, $row['nights_sold']);
        $this->assertSame(0, $row['revenue_before_commission']['amount']);
        $this->assertFalse($row['is_final']);

        $codes = array_column($row['flags'], 'code');
        $this->assertContains('incomplete_rates', $codes);
        $this->assertContains('incomplete_amounts', $codes);
    }

    public function test_an_unreconciled_refund_line_or_accounting_review_is_flagged(): void
    {
        $property = $this->property('CAD');
        $reservation = $this->stay($property, '2026-09-10', 2, 10000);

        DB::table('reservations')->where('id', $reservation->getKey())->update([
            'source_metadata' => json_encode([
                'hostex' => [
                    'requires_accounting_review' => true,
                    'financials' => ['details' => [
                        ['type' => 'ACCOMMODATION', 'money' => ['amount' => 20000, 'currency' => 'CAD']],
                        ['type' => 'CANCELLATION_REFUND_FROM_HOST', 'money' => ['amount' => -5000, 'currency' => 'CAD']],
                    ]],
                ],
            ]),
        ]);

        $row = $this->rowFor($property);

        // Shown before any refund, and said to be not final.
        $this->assertSame(20000, $row['revenue_before_commission']['amount']);
        $this->assertFalse($row['is_final']);

        $codes = array_column($row['flags'], 'code');
        $this->assertContains('unresolved_refunds', $codes);
        $this->assertContains('accounting_review', $codes);
    }

    public function test_nights_before_the_agreement_took_effect_carry_no_commission(): void
    {
        $property = $this->property('CAD');
        $this->stay($property, '2026-09-10', 4, 10000);

        ManagementAgreement::query()->forOrganization($this->organization)
            ->where('owner_id', $this->holder->getKey())
            ->update(['starts_on' => '2026-09-12']);

        $row = $this->rowFor($property);

        // All four nights' revenue is shown; only the two from the 12th are
        // commissionable.
        $this->assertSame(40000, $row['revenue_before_commission']['amount']);
        $this->assertSame(2000, $row['commission']['amount']);
        $this->assertContains('before_agreement', array_column($row['flags'], 'code'));
    }

    public function test_the_base_is_gross_accommodation_not_net_of_channel_commission(): void
    {
        $property = $this->property('CAD');
        $reservation = $this->stay($property, '2026-09-10', 2, 50000);

        // The channel kept 15% of the stay. Our 10% is still on the 1,000.00.
        DB::table('reservations')->where('id', $reservation->getKey())->update(['channel_commission' => 15000]);

        $row = $this->rowFor($property);

        $this->assertSame(100000, $row['revenue_before_commission']['amount']);
        $this->assertSame(10000, $row['commission']['amount']);
    }

    public function test_importing_the_same_stay_twice_changes_nothing(): void
    {
        $property = $this->property('CAD');
        $this->stay($property, '2026-09-10', 4, 25000, external: 'stay-1');

        $first = $this->rowFor($property);

        // The same external stay again: the unique index refuses a duplicate,
        // which is what a re-sync relies on.
        try {
            $this->stay($property, '2026-09-10', 4, 25000, external: 'stay-1');
            $this->fail('A duplicate external stay was inserted.');
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // expected
        }

        $this->assertSame($first['revenue_before_commission'], $this->rowFor($property)['revenue_before_commission']);
        $this->assertSame($first['commission'], $this->rowFor($property)['commission']);
    }

    public function test_the_portal_endpoint_explains_the_figures_in_plain_words(): void
    {
        $property = $this->property('CAD');
        $this->stay($property, '2026-09-10', 4, 25000);

        $response = $this->actingAsUser($this->client->fresh(), $this->organization)
            ->getJson('/api/v1/portal/owner/financials?from=2026-09-01&to=2026-09-30')
            ->assertOk()
            ->assertJsonPath('data.properties.0.revenue_after_commission.amount', 90000)
            ->assertJsonPath('data.commission.rate', 10);

        $this->assertStringContainsString('10%', $response->json('data.explanation.commission'));
        $this->assertStringContainsString('not a payment', $response->json('data.explanation.revenue_after_commission'));
        $this->assertStringNotContainsString('profit', strtolower($response->getContent()));
        $this->assertStringNotContainsString('payout', strtolower(json_encode($response->json('data.explanation'))));
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function financials(): array
    {
        $this->actingForOrganization($this->organization);

        return $this->app->make(ClientFinancials::class)->forOwner($this->holder->fresh(), $this->from, $this->to);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(Property $property): array
    {
        $rows = collect($this->financials()['properties'])->where('property_id', $property->getKey());

        $this->assertCount(1, $rows, 'Expected exactly one row for the property.');

        return $rows->first();
    }

    private function property(string $currency): Property
    {
        $this->actingForOrganization($this->organization);

        $property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => $currency,
            'base_rate' => 10000,
            'max_occupancy' => 4,
            'created_at' => '2026-01-01 00:00:00',
        ]);

        Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
            'currency' => $currency,
            'minimum_nights' => 1,
        ]);

        $this->app->make(ClientAccounts::class)->attachProperty($property);

        return $property;
    }

    /**
     * A stay built from its night rows, the way an import lays them down.
     */
    private function stay(
        Property $property,
        string $checkIn,
        int $nights,
        int $nightlyMinorUnits,
        ReservationStatus $status = ReservationStatus::Confirmed,
        ?string $external = null,
    ): Reservation {
        $this->actingForOrganization($this->organization);

        $listing = $property->listings()->firstOrFail();
        $in = CarbonImmutable::parse($checkIn);
        $out = $in->addDays($nights);
        $id = (string) Str::ulid();

        DB::table('reservations')->insert([
            'id' => $id,
            'organization_id' => $this->organization->getKey(),
            'property_id' => $property->getKey(),
            'listing_id' => $listing->getKey(),
            'confirmation_code' => 'HB-'.Str::upper(Str::random(6)),
            'status' => $status->value,
            'source' => 'hostex',
            'external_reservation_id' => $external ?? Str::random(10),
            'channel_account_id' => null,
            'check_in_date' => $in->toDateString(),
            'check_out_date' => $out->toDateString(),
            'nights' => $nights,
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
            'pets' => 0,
            'currency' => $property->currency,
            'base_currency' => $property->currency,
            'exchange_rate' => 1,
            'accommodation_total' => $nightlyMinorUnits * $nights,
            'grand_total' => $nightlyMinorUnits * $nights,
            'base_grand_total' => $nightlyMinorUnits * $nights,
            'booked_at' => $in->subDays(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = [];

        for ($i = 0; $i < $nights; $i++) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'organization_id' => $this->organization->getKey(),
                'reservation_id' => $id,
                'stay_date' => $in->addDays($i)->toDateString(),
                'rate_amount' => $nightlyMinorUnits,
                'currency' => $property->currency,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('reservation_nights')->insert($rows);

        return Reservation::query()->findOrFail($id);
    }
}
