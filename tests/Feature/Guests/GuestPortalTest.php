<?php

declare(strict_types=1);

namespace Tests\Feature\Guests;

use App\Domain\Guests\Services\GuestPortalService;
use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Payments\Services\PaymentScheduleService;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\DataObjects\ReservationRequest;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Users\Support\RoleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The guest portal.
 *
 * The link is the credential, so this file is mostly about what a leaked link
 * can reach. The answer has to be: one stay, and nothing that would let the
 * holder walk sideways into the guest's other bookings, the property's other
 * guests, or the manager's internal notes.
 */
class GuestPortalTest extends TestCase
{
    use RefreshDatabase;

    private GuestPortalService $portal;

    private Organization $organization;

    private Property $property;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->portal = $this->app->make(GuestPortalService::class);

        $this->organization = $this->createOrganization(['base_currency' => 'EUR']);

        $this->property = Property::factory()->active()->create([
            'organization_id' => $this->organization->getKey(),
            'currency' => 'EUR',
            'base_rate' => 10000,
            'cleaning_fee' => 5000,
            'max_occupancy' => 4,
            'address_line_1' => '12 Rua das Flores',
            'city' => 'Lisbon',
            'check_in_instructions' => 'The lockbox is to the left of the blue door.',
        ]);

        $this->listing = Listing::factory()->published()->create([
            'organization_id' => $this->organization->getKey(),
            'property_id' => $this->property->getKey(),
            'currency' => 'EUR',
            'minimum_nights' => 1,
        ]);
    }

    public function test_a_valid_link_opens_the_stay(): void
    {
        $reservation = $this->book();
        $token = $this->portal->issueToken($reservation);

        $this->getJson("/api/public/portal/{$token}")
            ->assertOk()
            ->assertJsonPath('data.confirmation_code', $reservation->confirmation_code)
            ->assertJsonPath('data.nights', 3)
            ->assertJsonPath('data.property.city', 'Lisbon');
    }

    public function test_an_unknown_link_and_an_expired_one_are_indistinguishable(): void
    {
        $reservation = $this->book();
        $token = $this->portal->issueToken($reservation);

        $reservation->forceFill([
            'portal_token_expires_at' => CarbonImmutable::now()->subDay(),
        ])->save();

        $expired = $this->getJson("/api/public/portal/{$token}");
        $unknown = $this->getJson('/api/public/portal/'.str_repeat('z', 40));

        // Telling somebody a token once existed is telling them something.
        $expired->assertNotFound();
        $unknown->assertNotFound();
        $this->assertSame($expired->json('message'), $unknown->json('message'));
    }

    public function test_the_portal_never_returns_the_managers_side_of_the_booking(): void
    {
        $reservation = $this->book();

        $reservation->forceFill([
            'internal_notes' => 'Owner is difficult about late checkouts.',
        ])->save();

        $body = $this->getJson('/api/public/portal/'.$this->portal->issueToken($reservation))
            ->assertOk()
            ->getContent();

        // The whole reason the portal assembles its own view rather than
        // serialising the reservation.
        $this->assertStringNotContainsString('difficult', $body);
        $this->assertStringNotContainsString('internal_notes', $body);
        $this->assertStringNotContainsString('channel_commission', $body);
        $this->assertStringNotContainsString('owner_id', $body);
    }

    public function test_the_address_is_withheld_until_the_stay_is_paid_for(): void
    {
        // Arriving tomorrow, so the timing condition is satisfied; the balance
        // is not.
        $reservation = $this->book(1, 4);

        $this->assertTrue($reservation->balanceDue()->isPositive());

        $this->getJson('/api/public/portal/'.$this->portal->issueToken($reservation))
            ->assertOk()
            // A city, yes. An exact address for an unpaid booking is how a
            // property gets visited by somebody who never intended to stay.
            ->assertJsonPath('data.property.address', null)
            ->assertJsonPath('data.property.city', 'Lisbon');
    }

    public function test_the_address_appears_once_paid_and_close_to_arrival(): void
    {
        $reservation = $this->book(1, 4);

        // Paid in full.
        $reservation->forceFill([
            'paid_total' => $reservation->grand_total,
            'balance_due' => 0,
        ])->save();

        $response = $this->getJson('/api/public/portal/'.$this->portal->issueToken($reservation->fresh()))
            ->assertOk();

        $this->assertSame('12 Rua das Flores', $response->json('data.property.address.line_1'));

        // The instructions travel with the address and for the same reason:
        // they are what a guest needs to get in.
        $this->assertStringContainsString(
            'lockbox',
            $response->json('data.property.address.check_in_instructions'),
        );
    }

    public function test_a_guest_can_complete_online_check_in(): void
    {
        $reservation = $this->book();
        $token = $this->portal->issueToken($reservation);

        $this->postJson("/api/public/portal/{$token}/check-in", [
            'estimated_arrival_time' => '18:30',
            'guests' => [
                ['first_name' => 'Ana', 'last_name' => 'Costa', 'nationality' => 'PT'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.online_check_in.completed', true)
            ->assertJsonPath('data.online_check_in.estimated_arrival_time', '18:30');

        // Completing check-in is not arriving: a guest can fill this in a week
        // early, and the operational fact is separate.
        $this->assertNull($reservation->fresh()->checked_in_at);
        $this->assertNotNull($reservation->fresh()->online_check_in_completed_at);
    }

    public function test_a_guest_can_message_the_host_and_read_the_thread(): void
    {
        $reservation = $this->book();
        $token = $this->portal->issueToken($reservation);

        $this->postJson("/api/public/portal/{$token}/messages", [
            'body' => 'Could we arrive an hour late?',
        ])
            ->assertCreated()
            ->assertJsonPath('data.from_guest', true);

        $this->getJson("/api/public/portal/{$token}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Could we arrive an hour late?');
    }

    public function test_a_guest_pays_a_named_instalment_not_an_arbitrary_amount(): void
    {
        $reservation = $this->book();
        $token = $this->portal->issueToken($reservation);

        $plan = $this->app->make(PaymentScheduleService::class)
            ->standardPlan($reservation, 30.0, 14);

        $this->getJson("/api/public/portal/{$token}/payments")
            ->assertOk()
            ->assertJsonCount(2, 'data.schedule');

        $response = $this->postJson("/api/public/portal/{$token}/payments", [
            'payment_schedule_id' => $plan[0]->getKey(),
        ])->assertOk();

        $this->assertSame((int) $plan[0]->amount, $response->json('data.paid.amount'));

        // Nothing pretends the local processor is a bank.
        $this->assertTrue($response->json('data.is_simulated'));
    }

    public function test_a_link_cannot_pay_another_bookings_instalment(): void
    {
        $mine = $this->book(10, 13);
        $theirs = $this->book(40, 43);

        $token = $this->portal->issueToken($mine);

        $theirPlan = $this->app->make(PaymentScheduleService::class)
            ->standardPlan($theirs, 30.0, 14);

        // Scoped to the reservation the token opens, so an instalment id from
        // another booking is not found rather than chargeable.
        $this->postJson("/api/public/portal/{$token}/payments", [
            'payment_schedule_id' => $theirPlan[0]->getKey(),
        ])->assertNotFound();
    }

    public function test_regenerating_a_link_invalidates_the_old_one(): void
    {
        $reservation = $this->book();

        $first = $this->portal->issueToken($reservation);
        $second = $this->portal->issueToken($reservation->fresh(), regenerate: true);

        $this->assertNotSame($first, $second);

        // How a guest who forwarded their link to the wrong person gets it
        // back.
        $this->getJson("/api/public/portal/{$first}")->assertNotFound();
        $this->getJson("/api/public/portal/{$second}")->assertOk();
    }

    public function test_staff_can_issue_a_link_for_a_booking(): void
    {
        $reservation = $this->book();
        $admin = $this->createUser($this->organization, [RoleRegistry::ORGANIZATION_ADMIN]);

        $response = $this->actingAsUser($admin, $this->organization)
            ->postJson("/api/v1/reservations/{$reservation->getKey()}/portal-link")
            ->assertOk();

        $this->assertStringContainsString('/api/public/portal/', $response->json('data.url'));
        $this->assertNotNull($response->json('data.expires_at'));
    }

    private function book(int $inDays = 20, int $outDays = 23): Reservation
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
