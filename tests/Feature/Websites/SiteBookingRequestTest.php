<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentSchedule;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A request to book made on a website: the guest's card is authorised, and
 * the host accepts — taking the money — or declines — letting it go — within
 * the site's request window, or the request is declined for them.
 */
class SiteBookingRequestTest extends TestCase
{
    use BuildsSites;
    use FakesStripe;
    use RefreshDatabase;

    private Organization $harbour;

    private Website $site;

    private Listing $barn;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->standInForStripe();

        $this->harbour = $this->createOrganization(['name' => 'Harbour Homes', 'base_currency' => 'EUR']);
        $this->manager = $this->createUser($this->harbour, [RoleRegistry::PROPERTY_MANAGER]);

        $this->site = Website::query()->create([
            'slug' => 'brand',
            'name' => 'Stay With Us',
            'kind' => Website::KIND_BRAND,
            'status' => Website::STATUS_LIVE,
            'hold_minutes' => 30,
            'request_hours' => 24,
        ]);

        $this->barn = $this->listing($this->harbour, 'Old Barn', ['instant_book' => false]);
        $this->show($this->barn);

        $this->app->make(TenantContext::class)->clear();
    }

    public function test_staff_see_a_waiting_request_and_its_deadline(): void
    {
        $checkout = $this->requested();

        $this->actingAsUser($this->manager, $this->harbour)
            ->getJson('/api/v1/reservations/'.$checkout->reservation_id)
            ->assertOk()
            ->assertJsonPath('data.website_request.status', 'requested')
            ->assertJsonPath('data.website_request.answer_by', $checkout->fresh()->hold_expires_at->toIso8601String());

        $this->actingAsUser($this->manager, $this->harbour)
            ->getJson('/api/v1/reservations')
            ->assertOk()
            ->assertJsonPath('data.0.website_request.status', 'requested');
    }

    public function test_accepting_a_request_takes_the_authorised_money_and_confirms(): void
    {
        $checkout = $this->requested();

        $this->actingAsUser($this->manager, $this->harbour)
            ->postJson('/api/v1/reservations/'.$checkout->reservation_id.'/website-request/accept')
            ->assertOk()
            ->assertJsonPath('data.status', ReservationStatus::Confirmed->value);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/payment_intents/pi_1/capture');

        $this->assertSame(WebsiteCheckout::STATUS_CONFIRMED, $checkout->fresh()->status);
        $payment = $this->withoutScope(fn () => Payment::query()->sole());
        $this->assertSame(PaymentStatus::Captured, $payment->status);

        $schedule = $this->withoutScope(fn () => PaymentSchedule::query()->where('reservation_id', $checkout->reservation_id)->orderBy('sequence')->get());
        $this->assertSame('paid', $schedule[0]->status);
        // The balance comes off the same card when it falls due.
        $this->assertTrue((bool) $schedule[1]->auto_charge);

        // Stripe's report of the capture it was asked for changes nothing.
        $this->webhook('payment_intent.succeeded', $this->intent($checkout->fresh(), 'succeeded'), id: 'evt_accepted')->assertOk();
        $this->assertSame(1, $this->withoutScope(fn () => Payment::query()->count()));
    }

    public function test_declining_a_request_lets_the_money_go_and_reopens_the_nights(): void
    {
        $checkout = $this->requested();

        $this->actingAsUser($this->manager, $this->harbour)
            ->postJson('/api/v1/reservations/'.$checkout->reservation_id.'/website-request/decline', ['reason' => 'Booked for a family wedding'])
            ->assertOk()
            ->assertJsonPath('data.status', ReservationStatus::Cancelled->value);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/payment_intents/pi_1/cancel');

        $this->assertSame(WebsiteCheckout::STATUS_DECLINED, $checkout->fresh()->status);
        $this->assertSame(PaymentStatus::Voided, $this->withoutScope(fn () => Payment::query()->sole())->status);
        $this->assertSame(0, $this->withoutScope(fn () => PaymentSchedule::query()->whereNot('status', 'cancelled')->count()));

        // Somebody else can ask for the nights now.
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->postJson('/sites/brand/api/listings/'.$this->barn->slug.'/quote', [
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->addDays(2)->toDateString(),
            'adults' => 2,
        ])->assertOk();
    }

    public function test_a_request_nobody_answers_is_declined_at_the_deadline(): void
    {
        $checkout = $this->requested();

        $this->travel(23)->hours();
        $this->artisan('websites:release-expired-holds')->assertSuccessful();
        $this->assertSame(WebsiteCheckout::STATUS_REQUESTED, $checkout->fresh()->status);

        $this->travel(2)->hours();
        $this->artisan('websites:release-expired-holds')->assertSuccessful();

        $this->assertSame(WebsiteCheckout::STATUS_DECLINED, $checkout->fresh()->status);
        $this->assertSame(
            ReservationStatus::Cancelled,
            $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id))->status,
        );
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/payment_intents/pi_1/cancel');
    }

    public function test_only_a_waiting_request_can_be_answered(): void
    {
        $checkout = $this->requested(authorise: false);

        // The guest has not finished giving their card.
        $this->actingAsUser($this->manager, $this->harbour)
            ->postJson('/api/v1/reservations/'.$checkout->reservation_id.'/website-request/accept')
            ->assertStatus(422)
            ->assertJsonPath('message', 'This request is not waiting for an answer.');

        $this->book($this->barn, CarbonImmutable::today()->addDays(60), CarbonImmutable::today()->addDays(62));
        $other = $this->withoutScope(fn () => Reservation::query()->where('status', ReservationStatus::Confirmed->value)->sole());

        $this->actingAsUser($this->manager, $this->harbour)
            ->postJson('/api/v1/reservations/'.$other->getKey().'/website-request/accept')
            ->assertNotFound();
    }

    public function test_answering_a_request_needs_permission_to_change_bookings(): void
    {
        $checkout = $this->requested();
        $viewer = $this->createUser($this->harbour, [RoleRegistry::OPERATIONS_MANAGER]);

        $this->actingAsUser($viewer, $this->harbour)
            ->postJson('/api/v1/reservations/'.$checkout->reservation_id.'/website-request/accept')
            ->assertForbidden();

        $this->assertSame(WebsiteCheckout::STATUS_REQUESTED, $checkout->fresh()->status);
    }

    // ------------------------------------------------------------------

    /**
     * A request on the barn, its card authorised by Stripe.
     */
    private function requested(bool $authorise = true): WebsiteCheckout
    {
        $checkIn = CarbonImmutable::today()->addDays(30);

        $quote = $this->postJson('/sites/brand/api/listings/'.$this->barn->slug.'/quote', [
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->addDays(2)->toDateString(),
            'adults' => 2,
        ])->assertOk()->json('quote_id');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/sites/brand/api/checkouts', [
            'quote_id' => $quote,
            'first_name' => 'Ana',
            'last_name' => 'Costa',
            'email' => 'ana@example.test',
            'phone' => '+351 912 345 678',
            'accept_policies' => true,
        ])->assertCreated();

        $checkout = WebsiteCheckout::query()->sole();

        if ($authorise) {
            $this->webhook('payment_intent.amount_capturable_updated', $this->intent($checkout, 'requires_capture'))->assertOk();
        }

        $this->app->make(TenantContext::class)->clear();

        return $checkout->fresh();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    private function withoutScope(callable $read): mixed
    {
        return $this->app->make(TenantContext::class)->withoutScope($read);
    }
}
