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
use App\Mail\Websites\PayBalance;
use App\Mail\Websites\RequestDeclined;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
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

    public function test_a_reason_longer_than_a_booking_keeps_is_refused_before_anything_is_let_go(): void
    {
        $checkout = $this->requested();

        $this->answer($checkout, 'decline', ['reason' => str_repeat('x', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/payment_intents/pi_1/cancel');
        $this->assertSame(WebsiteCheckout::STATUS_REQUESTED, $checkout->fresh()->status);

        $this->answer($checkout, 'decline', ['reason' => str_repeat('x', 255)])->assertOk();
        $this->assertSame(WebsiteCheckout::STATUS_DECLINED, $checkout->fresh()->status);
    }

    public function test_a_request_whose_card_authorisation_lapsed_can_still_be_declined(): void
    {
        $checkout = $this->requested();
        $this->authorisationGone = true;

        $this->answer($checkout, 'decline')
            ->assertOk()
            ->assertJsonPath('data.status', ReservationStatus::Cancelled->value);

        $this->assertSame(WebsiteCheckout::STATUS_DECLINED, $checkout->fresh()->status);
        $this->assertSame(PaymentStatus::Voided, $this->withoutScope(fn () => Payment::query()->sole())->status);
    }

    public function test_the_deadline_closes_a_request_whose_card_authorisation_lapsed(): void
    {
        $checkout = $this->requested();
        $this->authorisationGone = true;

        $this->travel(25)->hours();
        $this->artisan('websites:release-expired-holds')->assertSuccessful();

        $this->assertSame(WebsiteCheckout::STATUS_DECLINED, $checkout->fresh()->status);
    }

    public function test_a_request_whose_card_authorisation_lapsed_is_not_accepted_and_says_why(): void
    {
        $checkout = $this->requested();
        $this->authorisationGone = true;

        $this->answer($checkout, 'accept')
            ->assertUnprocessable()
            ->assertJsonPath('message', "The guest's card authorisation has lapsed, so nothing can be taken. Decline the request; the guest can book again.");

        $this->assertSame(WebsiteCheckout::STATUS_REQUESTED, $checkout->fresh()->status);
        $this->assertSame(ReservationStatus::Tentative, $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id))->status);
    }

    public function test_a_request_whose_payment_was_voided_in_habitat_can_still_be_declined(): void
    {
        $checkout = $this->requested();
        $this->withoutScope(fn () => Payment::query()->sole()->forceFill(['status' => PaymentStatus::Voided->value])->save());

        $this->answer($checkout, 'decline')->assertOk();

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/payment_intents/pi_1/cancel');
        $this->assertSame(WebsiteCheckout::STATUS_DECLINED, $checkout->fresh()->status);
    }

    public function test_stripe_being_unreachable_is_said_plainly_and_changes_nothing(): void
    {
        $checkout = $this->requested();
        $this->stripeDown = true;

        $this->answer($checkout, 'accept')
            ->assertStatus(503)
            ->assertJsonPath('message', 'Stripe could not be reached. Nothing has changed; try again in a minute.');
        $this->answer($checkout, 'decline')->assertStatus(503);

        $this->assertSame(WebsiteCheckout::STATUS_REQUESTED, $checkout->fresh()->status);
        $this->assertSame(ReservationStatus::Tentative, $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id))->status);
    }

    public function test_a_request_cancelled_in_habitat_lets_the_guests_money_go(): void
    {
        Mail::fake();
        $checkout = $this->requested();

        $this->actingAsUser($this->manager, $this->harbour)
            ->postJson('/api/v1/reservations/'.$checkout->reservation_id.'/cancel', ['reason' => 'The owner is staying'])
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/payment_intents/pi_1/cancel');
        $this->assertSame(WebsiteCheckout::STATUS_DECLINED, $checkout->fresh()->status);
        $this->assertSame(PaymentStatus::Voided, $this->withoutScope(fn () => Payment::query()->sole())->status);
        Mail::assertQueued(RequestDeclined::class, fn (RequestDeclined $mail): bool => $mail->hasTo('ana@example.test'));
    }

    public function test_a_request_confirmed_in_habitat_takes_the_authorised_money(): void
    {
        $checkout = $this->requested();

        $this->actingAsUser($this->manager, $this->harbour)
            ->postJson('/api/v1/reservations/'.$checkout->reservation_id.'/confirm')
            ->assertOk();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/payment_intents/pi_1/capture');
        $this->assertSame(WebsiteCheckout::STATUS_CONFIRMED, $checkout->fresh()->status);
        $this->assertSame(PaymentStatus::Captured, $this->withoutScope(fn () => Payment::query()->sole())->status);
    }

    public function test_a_request_confirmed_in_habitat_after_its_authorisation_lapsed_asks_the_guest_to_pay(): void
    {
        Mail::fake();
        $checkout = $this->requested();
        $this->authorisationGone = true;

        $this->actingAsUser($this->manager, $this->harbour)
            ->postJson('/api/v1/reservations/'.$checkout->reservation_id.'/confirm')
            ->assertOk();

        // The booking stands, as staff decided; the guest pays from their link.
        $this->assertSame(WebsiteCheckout::STATUS_CONFIRMED, $checkout->fresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id))->status);
        Mail::assertQueued(PayBalance::class, fn (PayBalance $mail): bool => $mail->hasTo('ana@example.test'));
        $this->get($checkout->fresh()->url())->assertOk()->assertSee('data-pay-balance', false);
    }

    public function test_the_deadline_takes_the_money_for_a_request_staff_confirmed(): void
    {
        $checkout = $this->requested();
        // Confirmed without Habitat's own steps running, as an import might.
        DB::table('reservations')->where('id', $checkout->reservation_id)->update(['status' => ReservationStatus::Confirmed->value]);

        $this->travel(25)->hours();
        $this->artisan('websites:release-expired-holds')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/payment_intents/pi_1/capture');
        $this->assertSame(WebsiteCheckout::STATUS_CONFIRMED, $checkout->fresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id))->status);
    }

    public function test_a_booking_cancelled_in_habitat_cannot_be_accepted(): void
    {
        $checkout = $this->requested();
        DB::table('reservations')->where('id', $checkout->reservation_id)->update(['status' => ReservationStatus::Cancelled->value]);

        $this->answer($checkout, 'accept')
            ->assertUnprocessable()
            ->assertJsonPath('message', "This booking was cancelled in Habitat. Decline the request to release the guest's card.");
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/payment_intents/pi_1/capture');

        $this->answer($checkout, 'decline')->assertOk();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/payment_intents/pi_1/cancel');
        $this->assertSame(WebsiteCheckout::STATUS_DECLINED, $checkout->fresh()->status);
    }

    public function test_a_balance_that_fell_overdue_while_waiting_is_still_taken_from_the_card(): void
    {
        $checkout = $this->requested();
        $balance = fn (): PaymentSchedule => $this->withoutScope(fn () => PaymentSchedule::query()->where('reservation_id', $checkout->reservation_id)->orderByDesc('sequence')->firstOrFail());
        $balance()->forceFill(['status' => 'overdue'])->save();

        $this->answer($checkout, 'accept')->assertOk();

        $this->assertTrue((bool) $balance()->auto_charge);
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
     * @param  array<string, mixed>  $body
     */
    private function answer(WebsiteCheckout $checkout, string $answer, array $body = []): TestResponse
    {
        return $this->actingAsUser($this->manager, $this->harbour)
            ->postJson('/api/v1/reservations/'.$checkout->reservation_id.'/website-request/'.$answer, $body);
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
