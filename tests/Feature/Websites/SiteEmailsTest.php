<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Users\Support\RoleRegistry;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Domain\Websites\Support\SiteFormat;
use App\Mail\Websites\BookingConfirmed;
use App\Mail\Websites\BookingRequested;
use App\Mail\Websites\NewWebsiteBooking;
use App\Mail\Websites\PayBalance;
use App\Mail\Websites\PaymentRefunded;
use App\Mail\Websites\PaymentRefundedToGuest;
use App\Mail\Websites\RequestAccepted;
use App\Mail\Websites\RequestDeclined;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * What a website booking tells the guest and the host, and when.
 */
class SiteEmailsTest extends TestCase
{
    use BuildsSites;
    use FakesStripe;
    use RefreshDatabase;

    private Organization $harbour;

    private Website $site;

    private Listing $flat;

    private Listing $barn;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->standInForStripe();

        $this->harbour = $this->createOrganization(['name' => 'Harbour Homes', 'base_currency' => 'EUR']);

        $this->site = Website::query()->create([
            'slug' => 'brand',
            'name' => 'Stay With Us',
            'kind' => Website::KIND_BRAND,
            'status' => Website::STATUS_LIVE,
            'hold_minutes' => 30,
            'request_hours' => 24,
            'settings' => ['contact_email' => 'hello@stay.test', 'contact_phone' => '+351 210 000 000'],
        ]);

        $this->flat = $this->listing($this->harbour, 'Sea View Flat', ['instant_book' => true]);
        $this->barn = $this->listing($this->harbour, 'Old Barn', ['instant_book' => false]);
        $this->show($this->flat);
        $this->show($this->barn);

        $this->app->make(TenantContext::class)->clear();
    }

    public function test_a_paid_booking_is_confirmed_to_the_guest_and_announced_to_the_host(): void
    {
        $checkout = $this->hold($this->flat);
        Mail::assertNothingQueued();

        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'))->assertOk();

        Mail::assertQueued(BookingConfirmed::class, fn (BookingConfirmed $mail): bool => $mail->hasTo('ana@example.test') && $mail->hasReplyTo('hello@stay.test'));
        Mail::assertQueued(NewWebsiteBooking::class, fn (NewWebsiteBooking $mail): bool => $mail->hasTo('hello@stay.test'));
        Mail::assertQueuedCount(2);

        $confirmation = (new BookingConfirmed($checkout->fresh()))->render();
        $this->assertStringContainsString('Sea View Flat', $confirmation);
        $this->assertStringContainsString($checkout->fresh()->url(), $confirmation);
        $this->assertStringContainsString('Ana', $confirmation);

        // A repeated report from Stripe sends nothing again.
        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'), id: 'evt_again')->assertOk();
        Mail::assertQueuedCount(2);
    }

    public function test_a_request_is_acknowledged_to_the_guest_and_put_to_the_host_with_its_deadline(): void
    {
        $checkout = $this->hold($this->barn);

        $this->webhook('payment_intent.amount_capturable_updated', $this->intent($checkout, 'requires_capture'))->assertOk();

        Mail::assertQueued(BookingRequested::class, fn (BookingRequested $mail): bool => $mail->hasTo('ana@example.test'));
        Mail::assertQueued(NewWebsiteBooking::class, fn (NewWebsiteBooking $mail): bool => $mail->hasTo('hello@stay.test'));

        $host = (new NewWebsiteBooking($checkout->fresh()))->render();
        $this->assertStringContainsString('request', strtolower($host));
        $this->assertStringContainsString('Answer by', $host);
    }

    public function test_the_guest_hears_when_the_host_accepts(): void
    {
        $checkout = $this->requested();

        $this->actingAsUser($this->createUser($this->harbour, [RoleRegistry::PROPERTY_MANAGER]), $this->harbour)
            ->postJson('/api/v1/reservations/'.$checkout->reservation_id.'/website-request/accept')
            ->assertOk();

        Mail::assertQueued(RequestAccepted::class, fn (RequestAccepted $mail): bool => $mail->hasTo('ana@example.test'));
        Mail::assertNotQueued(BookingConfirmed::class);
    }

    public function test_the_guest_hears_when_the_host_declines_and_why(): void
    {
        $checkout = $this->requested();

        $this->actingAsUser($this->createUser($this->harbour, [RoleRegistry::PROPERTY_MANAGER]), $this->harbour)
            ->postJson('/api/v1/reservations/'.$checkout->reservation_id.'/website-request/decline', ['reason' => 'Booked for a family wedding'])
            ->assertOk();

        Mail::assertQueued(RequestDeclined::class, fn (RequestDeclined $mail): bool => $mail->hasTo('ana@example.test'));
        $this->assertStringContainsString('Booked for a family wedding', (new RequestDeclined($checkout->fresh(), 'Booked for a family wedding'))->render());
    }

    public function test_an_unanswered_request_tells_the_guest_too(): void
    {
        $this->requested();

        $this->travel(25)->hours();
        $this->artisan('websites:release-expired-holds')->assertSuccessful();

        Mail::assertQueued(RequestDeclined::class, fn (RequestDeclined $mail): bool => $mail->hasTo('ana@example.test'));
    }

    public function test_a_site_without_a_contact_email_sends_the_host_nothing(): void
    {
        $this->site->forceFill(['settings' => []])->save();
        $checkout = $this->hold($this->flat);

        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'))->assertOk();

        Mail::assertQueued(BookingConfirmed::class);
        Mail::assertNotQueued(NewWebsiteBooking::class);
    }

    public function test_the_pay_balance_email_says_what_is_owed_and_where_to_pay(): void
    {
        $checkout = $this->hold($this->flat);
        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'))->assertOk();

        $mail = new PayBalance($checkout->fresh(), Money::of(24500, 'EUR'), 'Your card has insufficient funds.');
        $body = $mail->render();

        $this->assertStringContainsString('€245.00', $body);
        $this->assertStringContainsString($checkout->fresh()->url(), $body);
        $this->assertStringContainsString('Your card has insufficient funds.', $body);
    }

    public function test_the_host_replies_straight_to_the_guest(): void
    {
        $checkout = $this->hold($this->flat);

        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'))->assertOk();

        Mail::assertQueued(NewWebsiteBooking::class, fn (NewWebsiteBooking $mail): bool => $mail->hasReplyTo('ana@example.test') && ! $mail->hasReplyTo('hello@stay.test'));
    }

    public function test_a_payment_for_a_hold_staff_cancelled_is_refunded_and_both_are_told(): void
    {
        $checkout = $this->hold($this->flat);
        $this->actingForOrganization($this->harbour);
        $this->app->make(ReservationService::class)->cancel(Reservation::query()->sole(), 'Owner blocked the dates');
        $this->app->make(TenantContext::class)->clear();

        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'))->assertOk();

        Mail::assertQueued(PaymentRefunded::class, fn (PaymentRefunded $mail): bool => $mail->hasTo('ana@example.test'));
        Mail::assertQueued(PaymentRefundedToGuest::class, fn (PaymentRefundedToGuest $mail): bool => $mail->hasTo('hello@stay.test'));
        Mail::assertNotQueued(BookingConfirmed::class);
        Mail::assertNotQueued(NewWebsiteBooking::class);

        $guest = (new PaymentRefunded($checkout->fresh(), Money::of($checkout->due_now_amount, 'EUR'), 'The booking had been cancelled when your payment arrived.'))->render();
        $this->assertStringContainsString('The booking had been cancelled when your payment arrived.', $guest);
        $this->assertStringContainsString(SiteFormat::money(Money::of($checkout->due_now_amount, 'EUR')), $guest);

        $host = (new PaymentRefundedToGuest($checkout->fresh(), Money::of($checkout->due_now_amount, 'EUR'), 'The booking had been cancelled when the payment arrived.'))->render();
        $this->assertStringContainsString('Ana Costa', $host);
        $this->assertStringContainsString('refunded', $host);
    }

    // ------------------------------------------------------------------

    private function hold(Listing $listing): WebsiteCheckout
    {
        $checkIn = CarbonImmutable::today()->addDays(30);

        $quote = $this->postJson('/sites/brand/api/listings/'.$listing->slug.'/quote', [
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->addDays(3)->toDateString(),
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

        return WebsiteCheckout::query()->sole();
    }

    private function requested(): WebsiteCheckout
    {
        $checkout = $this->hold($this->barn);
        $this->webhook('payment_intent.amount_capturable_updated', $this->intent($checkout, 'requires_capture'))->assertOk();
        $this->app->make(TenantContext::class)->clear();

        return $checkout->fresh();
    }
}
