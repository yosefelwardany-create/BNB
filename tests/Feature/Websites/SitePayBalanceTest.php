<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentSchedule;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Mail\Websites\PayBalance;
use App\Mail\Websites\PaymentRefunded;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * When the balance cannot be taken from the saved card, the guest is asked
 * to pay it themselves, from their booking link.
 */
class SitePayBalanceTest extends TestCase
{
    use BuildsSites;
    use FakesStripe;
    use RefreshDatabase;

    private Organization $harbour;

    private Website $site;

    private Listing $flat;

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
            'settings' => ['contact_email' => 'hello@stay.test'],
        ]);

        $this->flat = $this->listing($this->harbour, 'Sea View Flat', ['instant_book' => true]);
        $this->show($this->flat);

        $this->app->make(TenantContext::class)->clear();
    }

    public function test_a_refused_balance_emails_the_guest_a_link_to_pay(): void
    {
        $checkout = $this->refusedBalance();

        Mail::assertQueued(PayBalance::class, fn (PayBalance $mail): bool => $mail->hasTo('ana@example.test') && $mail->checkout->is($checkout));
    }

    public function test_the_booking_page_offers_to_pay_what_the_card_did_not(): void
    {
        $checkout = $this->refusedBalance();
        $balance = $this->balance($checkout);

        $this->get($checkout->fresh()->url())
            ->assertOk()
            ->assertSee('data-pay-balance', false)
            ->assertSee('€'.number_format($balance->amount / 100, 2))
            ->assertSee('Your card has insufficient funds.');
    }

    public function test_paying_the_balance_on_the_booking_page_settles_it(): void
    {
        $checkout = $this->refusedBalance();
        $balance = $this->balance($checkout);

        $this->postJson($checkout->fresh()->url().'/pay-balance')
            ->assertOk()
            ->assertJsonPath('client_secret', 'pi_paybalance_secret');

        Http::assertSent(function (Request $request) use ($balance): bool {
            parse_str($request->body(), $form);

            return $request->url() === 'https://api.stripe.com/v1/payment_intents'
                && ($form['metadata']['purpose'] ?? null) === 'pay_balance'
                && $form['amount'] === (string) $balance->amount
                && $form['customer'] === 'cus_1'
                && ! isset($form['off_session']);
        });

        $this->webhook('payment_intent.succeeded', [
            'id' => 'pi_paybalance',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount_received' => $balance->amount,
            'currency' => 'eur',
            'customer' => 'cus_1',
            'payment_method' => 'pm_2',
            'latest_charge' => 'ch_1',
            'metadata' => ['website_checkout_id' => $checkout->getKey(), 'purpose' => 'pay_balance'],
        ], id: 'evt_paybalance')->assertOk();

        $this->assertSame('paid', $this->balance($checkout)->status);
        $this->assertSame(1, $this->withoutScope(fn () => Payment::query()->where('provider_reference', 'pi_paybalance')->where('status', PaymentStatus::Captured->value)->count()));

        // Nothing left to pay, so nothing is offered.
        $this->get($checkout->fresh()->url())->assertOk()->assertDontSee('data-pay-balance', false);
    }

    public function test_nothing_is_offered_while_the_balance_is_not_due(): void
    {
        $checkout = $this->paid();

        $this->get($checkout->fresh()->url())->assertOk()->assertDontSee('data-pay-balance', false);
        $this->postJson($checkout->fresh()->url().'/pay-balance')->assertStatus(422);
    }

    public function test_a_cancelled_booking_has_no_balance_to_pay(): void
    {
        $checkout = $this->refusedBalance();
        $this->cancel($checkout);

        $this->get($checkout->fresh()->url())->assertOk()->assertDontSee('data-pay-balance', false);
        $this->postJson($checkout->fresh()->url().'/pay-balance')->assertStatus(422);
    }

    public function test_a_balance_paid_after_the_booking_was_cancelled_is_refunded_and_the_guest_told(): void
    {
        $checkout = $this->refusedBalance();
        $balance = $this->balance($checkout);
        $this->postJson($checkout->fresh()->url().'/pay-balance')->assertOk();

        // Cancelled while the guest's form was open.
        $this->cancel($checkout);

        $this->webhook('payment_intent.succeeded', [
            'id' => 'pi_paybalance',
            'object' => 'payment_intent',
            'status' => 'succeeded',
            'amount_received' => $balance->amount,
            'currency' => 'eur',
            'customer' => 'cus_1',
            'payment_method' => 'pm_2',
            'latest_charge' => 'ch_1',
            'metadata' => ['website_checkout_id' => $checkout->getKey(), 'purpose' => 'pay_balance'],
        ], id: 'evt_paybalance')->assertOk();

        Http::assertSent(function (Request $request) use ($balance): bool {
            parse_str($request->body(), $form);

            return $request->url() === 'https://api.stripe.com/v1/refunds'
                && $form['payment_intent'] === 'pi_paybalance'
                && $form['amount'] === (string) $balance->amount;
        });
        $this->assertSame(PaymentStatus::Refunded, $this->withoutScope(fn () => Payment::query()->where('provider_reference', 'pi_paybalance')->sole())->status);
        Mail::assertQueued(PaymentRefunded::class, fn (PaymentRefunded $mail): bool => $mail->hasTo('ana@example.test'));
    }

    public function test_a_charge_stripe_will_try_again_does_not_ask_the_guest_to_pay(): void
    {
        $checkout = $this->paid();
        $this->travelTo($checkout->hold_expires_at->addDays(16)->setTime(9, 0));
        $this->offSession = 'processing_error';
        $this->artisan('payments:process-due-schedules')->assertSuccessful();

        // The scheduler tries again within the hour; the guest has nothing to do.
        $this->assertTrue((bool) $this->balance($checkout)->auto_charge);
        Mail::assertNotQueued(PayBalance::class);
    }

    // ------------------------------------------------------------------

    private function paid(): WebsiteCheckout
    {
        $checkIn = CarbonImmutable::today()->addDays(30);

        $quote = $this->postJson('/sites/brand/api/listings/'.$this->flat->slug.'/quote', [
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

        $checkout = WebsiteCheckout::query()->sole();
        $this->webhook('payment_intent.succeeded', $this->intent($checkout, 'succeeded'))->assertOk();

        return $checkout->fresh();
    }

    private function refusedBalance(): WebsiteCheckout
    {
        $checkout = $this->paid();
        $this->travelTo($checkout->hold_expires_at->addDays(16)->setTime(9, 0));
        $this->offSession = 'decline';
        $this->artisan('payments:process-due-schedules')->assertSuccessful();

        return $checkout->fresh();
    }

    private function cancel(WebsiteCheckout $checkout): void
    {
        $this->actingForOrganization($this->harbour);
        $this->app->make(ReservationService::class)->cancel(Reservation::query()->findOrFail($checkout->reservation_id), 'Balance not paid');
        $this->app->make(TenantContext::class)->clear();
    }

    private function balance(WebsiteCheckout $checkout): PaymentSchedule
    {
        return $this->withoutScope(fn () => PaymentSchedule::query()->where('reservation_id', $checkout->reservation_id)->orderByDesc('sequence')->first());
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
