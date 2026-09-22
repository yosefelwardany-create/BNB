<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationApiTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 12000,
            'cleaning_fee' => 4000,
            'max_occupancy' => 4,
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);
    }

    public function test_an_agent_can_quote_a_stay_without_booking_it(): void
    {
        $agent = $this->createUser($this->property->organization, [RoleRegistry::RESERVATIONS_AGENT]);

        $response = $this->actingAsUser($agent, $this->property->organization)
            ->postJson('/api/v1/reservations/quote', [
                'listing_id' => $this->listing->getKey(),
                'check_in' => $this->day(30),
                'check_out' => $this->day(33),
                'adults' => 2,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.totals.accommodation.amount', 36000)
            ->assertJsonPath('data.totals.fees.amount', 4000)
            ->assertJsonPath('data.totals.grand_total.amount', 40000)
            ->assertJsonCount(3, 'data.nights');

        // Nothing was created.
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_an_agent_can_create_a_reservation(): void
    {
        $agent = $this->createUser($this->property->organization, [RoleRegistry::RESERVATIONS_AGENT]);

        $response = $this->actingAsUser($agent, $this->property->organization)
            ->postJson('/api/v1/reservations', [
                'listing_id' => $this->listing->getKey(),
                'check_in' => $this->day(30),
                'check_out' => $this->day(33),
                'adults' => 2,
                'guest' => [
                    'first_name' => 'Ines',
                    'last_name' => 'Costa',
                    'email' => 'ines@example.test',
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.stay.nights', 3)
            ->assertJsonPath('data.financials.grand_total.amount', 40000);

        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseCount('guests', 1);
    }

    public function test_double_booking_the_same_dates_returns_a_conflict(): void
    {
        $agent = $this->createUser($this->property->organization, [RoleRegistry::RESERVATIONS_AGENT]);

        $payload = [
            'listing_id' => $this->listing->getKey(),
            'check_in' => $this->day(40),
            'check_out' => $this->day(43),
            'guest' => ['first_name' => 'First', 'email' => 'first@example.test'],
        ];

        $this->actingAsUser($agent, $this->property->organization)
            ->postJson('/api/v1/reservations', $payload)
            ->assertCreated();

        $this->actingAsUser($agent, $this->property->organization)
            ->postJson('/api/v1/reservations', array_merge($payload, [
                'guest' => ['first_name' => 'Second', 'email' => 'second@example.test'],
            ]))
            ->assertStatus(409);
    }

    public function test_a_departure_before_arrival_is_rejected(): void
    {
        $agent = $this->createUser($this->property->organization, [RoleRegistry::RESERVATIONS_AGENT]);

        $this->actingAsUser($agent, $this->property->organization)
            ->postJson('/api/v1/reservations', [
                'listing_id' => $this->listing->getKey(),
                'check_in' => $this->day(40),
                'check_out' => $this->day(38),
                'guest' => ['first_name' => 'Backwards', 'email' => 'back@example.test'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('check_out');
    }

    public function test_a_cleaner_cannot_see_or_create_reservations(): void
    {
        $cleaner = $this->createUser($this->property->organization, [RoleRegistry::CLEANER]);

        $this->actingAsUser($cleaner, $this->property->organization)
            ->getJson('/api/v1/reservations')
            ->assertStatus(403);

        $this->actingAsUser($cleaner, $this->property->organization)
            ->postJson('/api/v1/reservations', [
                'listing_id' => $this->listing->getKey(),
                'check_in' => $this->day(30),
                'check_out' => $this->day(32),
                'guest' => ['first_name' => 'Nope'],
            ])
            ->assertStatus(403);
    }

    public function test_the_refund_preview_explains_its_arithmetic(): void
    {
        $manager = $this->createUser($this->property->organization, [RoleRegistry::PROPERTY_MANAGER]);

        $policy = \App\Domain\Properties\Models\CancellationPolicy::query()
            ->where('slug', 'moderate')->firstOrFail();

        $this->listing->forceFill(['cancellation_policy_id' => $policy->getKey()])->save();

        $created = $this->actingAsUser($manager, $this->property->organization)
            ->postJson('/api/v1/reservations', [
                'listing_id' => $this->listing->getKey(),
                'check_in' => $this->day(60),
                'check_out' => $this->day(63),
                'guest' => ['first_name' => 'Refund', 'email' => 'refund@example.test'],
            ])->json('data.id');

        $response = $this->actingAsUser($manager, $this->property->organization)
            ->getJson("/api/v1/reservations/{$created}/refund-preview");

        $response->assertOk()
            ->assertJsonStructure(['refund', 'retained', 'refund_percent', 'explanation', 'breakdown']);

        $this->assertStringContainsString('day(s) before arrival', $response->json('explanation'));
    }

    public function test_the_calendar_returns_day_by_day_state(): void
    {
        $manager = $this->createUser($this->property->organization, [RoleRegistry::PROPERTY_MANAGER]);

        $this->actingAsUser($manager, $this->property->organization)
            ->postJson('/api/v1/reservations', [
                'listing_id' => $this->listing->getKey(),
                'check_in' => $this->day(10),
                'check_out' => $this->day(13),
                'guest' => ['first_name' => 'Calendar', 'email' => 'cal@example.test'],
            ])->assertCreated();

        $response = $this->actingAsUser($manager, $this->property->organization)
            ->getJson('/api/v1/calendar?from='.$this->day(8).'&to='.$this->day(16));

        $response->assertOk()
            ->assertJsonCount(1, 'listings')
            ->assertJsonCount(1, 'reservations');

        $days = collect($response->json('listings.0.days'))->keyBy('date');

        $this->assertTrue($days[$this->day(8)]['available']);
        $this->assertFalse($days[$this->day(10)]['available']);
        // The checkout date is free again.
        $this->assertTrue($days[$this->day(13)]['available']);
    }

    public function test_calendar_days_can_be_updated_in_bulk(): void
    {
        $manager = $this->createUser($this->property->organization, [RoleRegistry::PROPERTY_MANAGER]);

        $this->actingAsUser($manager, $this->property->organization)
            ->patchJson("/api/v1/calendar/listings/{$this->listing->getKey()}/days", [
                'from' => $this->day(20),
                'to' => $this->day(40),
                'days_of_week' => [6],       // Saturdays only
                'minimum_nights' => 7,
                'closed_to_arrival' => false,
            ])
            ->assertOk()
            ->assertJsonPath('updated', 3);

        $this->assertDatabaseCount('calendar_days', 3);
    }

    public function test_blocking_dates_that_are_already_booked_is_refused(): void
    {
        $manager = $this->createUser($this->property->organization, [RoleRegistry::PROPERTY_MANAGER]);

        $this->actingAsUser($manager, $this->property->organization)
            ->postJson('/api/v1/reservations', [
                'listing_id' => $this->listing->getKey(),
                'check_in' => $this->day(50),
                'check_out' => $this->day(53),
                'guest' => ['first_name' => 'Booked', 'email' => 'booked@example.test'],
            ])->assertCreated();

        $this->actingAsUser($manager, $this->property->organization)
            ->postJson('/api/v1/calendar/blocks', [
                'property_id' => $this->property->getKey(),
                'kind' => 'maintenance',
                'start_date' => $this->day(51),
                'end_date' => $this->day(55),
            ])
            ->assertStatus(409)
            ->assertJsonStructure(['message', 'conflicts']);
    }

    public function test_a_reservation_from_another_organization_is_not_reachable(): void
    {
        $manager = $this->createUser($this->property->organization, [RoleRegistry::PROPERTY_MANAGER]);

        $id = $this->actingAsUser($manager, $this->property->organization)
            ->postJson('/api/v1/reservations', [
                'listing_id' => $this->listing->getKey(),
                'check_in' => $this->day(70),
                'check_out' => $this->day(72),
                'guest' => ['first_name' => 'Private', 'email' => 'private@example.test'],
            ])->json('data.id');

        // A different company, with its own administrator.
        $other = $this->createOrganization(['name' => 'Rival Lettings']);
        $intruder = $this->createUser($other, [RoleRegistry::ORGANIZATION_ADMIN]);

        $this->actingAsUser($intruder, $other)
            ->getJson("/api/v1/reservations/{$id}")
            ->assertStatus(404);
    }

    private function day(int $offset): string
    {
        return CarbonImmutable::now($this->property->timezone)->addDays($offset)->toDateString();
    }
}
