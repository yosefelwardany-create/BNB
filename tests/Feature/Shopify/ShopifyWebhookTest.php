<?php

declare(strict_types=1);

namespace Tests\Feature\Shopify;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentSchedule;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Services\PaymentService;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Services\ReservationService;
use App\Domain\Users\Support\RoleRegistry;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Domain\Websites\Models\WebsiteShopifyConnection;
use App\Domain\Websites\Models\WebsiteShopifyOrder;
use App\Domain\Websites\Services\ShopifyPayments;
use App\Domain\Websites\Services\WebsiteCheckoutService;
use App\Mail\Websites\BookingConfirmed;
use App\Mail\Websites\NewWebsiteBooking;
use App\Mail\Websites\PayBalance;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Websites\BuildsSites;
use Tests\TestCase;

/**
 * Shopify telling Habitat a store's order was paid, refunded, or that the
 * platform's app was removed. Nothing is believed until Shopify's signature
 * checks out, each message is acted on once, and a paid order is matched to
 * its checkout by the attribute Habitat put on it, for the amount Habitat
 * asked.
 */
class ShopifyWebhookTest extends TestCase
{
    use BuildsSites;
    use RefreshDatabase;

    private const SHOP = 'harbour-homes.myshopify.com';

    private Organization $harbour;

    private Website $site;

    private Listing $flat;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.key' => null, 'services.stripe.secret' => null, 'services.stripe.webhook_secret' => null,
            'services.shopify.api_version' => '2026-10',
            'mail.default' => 'array',
        ]);
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(function () {
            static $next = 1;

            return Http::response(['data' => ['draftOrderCreate' => ['userErrors' => [], 'draftOrder' => [
                'id' => 'gid://shopify/DraftOrder/'.$next++, 'name' => '#D'.$next, 'invoiceUrl' => 'https://'.self::SHOP.'/1/invoices/'.$next,
            ]]]]);
        });

        $this->harbour = $this->createOrganization(['name' => 'Harbour Homes', 'base_currency' => 'EUR']);
        $this->site = Website::query()->create([
            'slug' => 'brand', 'name' => 'Stay With Us', 'kind' => Website::KIND_BRAND, 'status' => Website::STATUS_LIVE,
            'hold_minutes' => 30, 'request_hours' => 24, 'settings' => ['contact_email' => 'stay@harbour.test'],
        ]);
        $this->flat = $this->listing($this->harbour, 'Sea View Flat', ['instant_book' => true]);
        $this->show($this->flat);

        WebsiteShopifyConnection::query()->create([
            'website_id' => $this->site->getKey(), 'shop_domain' => self::SHOP, 'shop_name' => 'Harbour Homes', 'currency' => 'EUR',
            'client_id' => 'client-id', 'client_secret' => 'client-secret',
            'access_token' => 'shpat_store', 'status' => WebsiteShopifyConnection::STATUS_CONNECTED, 'connected_at' => now(),
        ]);

        $this->app->make(TenantContext::class)->clear();
    }

    public function test_a_paid_order_confirms_the_booking_as_money_the_client_holds(): void
    {
        $checkout = $this->held();

        $this->paid($checkout)->assertOk();

        $checkout = $checkout->fresh();
        $this->assertSame(WebsiteCheckout::STATUS_CONFIRMED, $checkout->status);

        $reservation = $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id));
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);

        $payment = $this->withoutScope(fn () => Payment::query()->where('reservation_id', $reservation->getKey())->sole());
        $this->assertNull($payment->provider);
        $this->assertSame('shopify', $payment->method);
        $this->assertSame('#1042', $payment->provider_reference);
        $this->assertFalse($payment->is_collected_by_us);
        $this->assertSame($checkout->due_now_amount, (int) $payment->amount);

        $order = WebsiteShopifyOrder::query()->sole();
        $this->assertSame(WebsiteShopifyOrder::STATUS_PAID, $order->status);
        $this->assertSame('#1042', $order->order_name);

        Mail::assertQueued(BookingConfirmed::class);
        Mail::assertQueued(NewWebsiteBooking::class);
    }

    public function test_a_forged_or_repeated_message_changes_nothing(): void
    {
        $checkout = $this->held();

        $this->paid($checkout, secret: 'not-the-secret')->assertUnauthorized();
        $this->assertSame(WebsiteCheckout::STATUS_HOLDING, $checkout->fresh()->status);

        $this->paid($checkout, webhookId: 'same-delivery')->assertOk();
        $this->paid($checkout, webhookId: 'same-delivery')->assertOk();
        // Delivered again under a new id, the order is already paid.
        $this->paid($checkout)->assertOk();

        $this->assertSame(1, $this->withoutScope(fn () => Payment::query()->where('method', 'shopify')->count()));
    }

    public function test_a_paid_order_for_another_amount_is_not_confirmed(): void
    {
        $checkout = $this->held();

        $this->paid($checkout, total: '1.00')->assertOk();

        $this->assertSame(WebsiteCheckout::STATUS_HOLDING, $checkout->fresh()->status);
        $this->assertSame(0, $this->withoutScope(fn () => Payment::query()->where('method', 'shopify')->count()));
        $this->assertSame(WebsiteShopifyOrder::STATUS_FAILED, WebsiteShopifyOrder::query()->sole()->status);
        $this->assertStringContainsString('#1042', (string) $checkout->fresh()->payment_error);
    }

    public function test_a_late_payment_rebooks_free_nights(): void
    {
        $checkout = $this->held();
        $this->app->make(WebsiteCheckoutService::class)->release($checkout->fresh());
        $this->assertSame(WebsiteCheckout::STATUS_EXPIRED, $checkout->fresh()->status);

        $this->paid($checkout)->assertOk();

        $this->assertSame(WebsiteCheckout::STATUS_CONFIRMED, $checkout->fresh()->status);
    }

    public function test_a_late_payment_for_taken_nights_is_kept_and_flagged_for_a_refund(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $checkout = $this->held($checkIn);
        $this->app->make(WebsiteCheckoutService::class)->release($checkout->fresh());
        $this->book($this->flat, $checkIn, $checkIn->addDays(3));

        $this->paid($checkout)->assertOk();

        $checkout = $checkout->fresh();
        $this->assertNotSame(WebsiteCheckout::STATUS_CONFIRMED, $checkout->status);
        $this->assertStringContainsString('Refund order #1042 in Shopify', (string) $checkout->payment_error);
        // The money is the client's, recorded as theirs, whatever becomes of it.
        $this->assertSame(1, $this->withoutScope(fn () => Payment::query()->where('method', 'shopify')->count()));
    }

    public function test_a_paid_balance_settles_what_is_due(): void
    {
        $checkout = $this->held(CarbonImmutable::today()->addDays(90));
        $this->paid($checkout)->assertOk();
        $balance = $checkout->total_amount - $checkout->due_now_amount;

        $order = WebsiteShopifyOrder::query()->create([
            'website_checkout_id' => $checkout->getKey(), 'purpose' => WebsiteShopifyOrder::PURPOSE_BALANCE,
            'draft_order_id' => 'gid://shopify/DraftOrder/999', 'invoice_url' => 'https://'.self::SHOP.'/1/invoices/999',
            'amount' => $balance, 'currency' => 'EUR', 'status' => WebsiteShopifyOrder::STATUS_OPEN,
        ]);

        $this->paid($checkout, total: number_format($balance / 100, 2, '.', ''), purpose: 'balance', name: '#1043')->assertOk();

        $this->assertSame(WebsiteShopifyOrder::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(2, $this->withoutScope(fn () => Payment::query()->where('method', 'shopify')->count()));
    }

    public function test_a_balance_falling_due_is_asked_for_once_and_the_email_says_how_to_pay(): void
    {
        $checkout = $this->held(CarbonImmutable::today()->addDays(90));
        $this->paid($checkout)->assertOk();

        // The confirmation says the rest is paid on the store, not taken from a card.
        Mail::assertQueued(BookingConfirmed::class, function (BookingConfirmed $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'link to pay it on our store') && ! str_contains($html, 'taken from the same card');
        });

        $this->withoutScope(fn () => PaymentSchedule::query()->where('reservation_id', $checkout->reservation_id)->where('sequence', '>', 1)
            ->update(['due_on' => CarbonImmutable::today()->toDateString()]));

        $this->artisan('websites:remind-shopify-balances')->assertSuccessful();
        $this->artisan('websites:remind-shopify-balances')->assertSuccessful();

        Mail::assertQueued(PayBalance::class, 1);
        Mail::assertQueued(PayBalance::class, function (PayBalance $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'is now due') && ! str_contains($html, 'from your card');
        });
    }

    public function test_amounts_are_read_in_their_currencys_own_decimals(): void
    {
        $this->assertSame(15000, ShopifyPayments::amountOf(['total_price' => '150.00', 'currency' => 'EUR'])?->minorUnits);
        $this->assertSame(15000, ShopifyPayments::amountOf(['total_price' => '15000', 'currency' => 'JPY'])?->minorUnits);
        $this->assertSame(1500, ShopifyPayments::amountOf(['total_price' => '1.500', 'currency' => 'KWD'])?->minorUnits);
        $this->assertNull(ShopifyPayments::amountOf(['total_price' => 'lots', 'currency' => 'EUR']));
    }

    public function test_a_balance_paid_twice_under_two_deliveries_is_settled_once(): void
    {
        $checkout = $this->held(CarbonImmutable::today()->addDays(90));
        $this->paid($checkout)->assertOk();
        $balance = $checkout->total_amount - $checkout->due_now_amount;

        WebsiteShopifyOrder::query()->create([
            'website_checkout_id' => $checkout->getKey(), 'purpose' => WebsiteShopifyOrder::PURPOSE_BALANCE,
            'draft_order_id' => 'gid://shopify/DraftOrder/999', 'invoice_url' => 'https://'.self::SHOP.'/1/invoices/999',
            'amount' => $balance, 'currency' => 'EUR', 'status' => WebsiteShopifyOrder::STATUS_OPEN,
        ]);

        $total = number_format($balance / 100, 2, '.', '');
        $this->paid($checkout, total: $total, purpose: 'balance', name: '#1043')->assertOk();
        WebsiteShopifyOrder::query()->where('purpose', 'balance')->update(['status' => WebsiteShopifyOrder::STATUS_OPEN]);
        $this->paid($checkout, total: $total, purpose: 'balance', name: '#1043')->assertOk();

        $paid = $this->withoutScope(fn () => PaymentSchedule::query()->where('reservation_id', $checkout->reservation_id)->sum('paid_amount'));
        $this->assertSame($checkout->total_amount, (int) $paid);
    }

    public function test_a_refund_made_in_shopify_is_recorded_against_the_booking(): void
    {
        $checkout = $this->held();
        $this->paid($checkout)->assertOk();

        $this->refunded(555, '50.00')->assertOk();
        // Reported again, under another delivery: recorded once.
        $this->refunded(555, '50.00')->assertOk();

        $payment = $this->withoutScope(fn () => Payment::query()->where('method', 'shopify')->sole());
        $this->assertSame(5000, (int) $payment->refunded_amount);
        $this->assertSame(1, $this->withoutScope(fn () => Refund::query()->where('provider_reference', 'shopify-refund:555')->count()));

        // A refund is money back, not a cancellation.
        $this->assertSame(WebsiteCheckout::STATUS_CONFIRMED, $checkout->fresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id))->status);
    }

    public function test_a_shopify_payment_is_not_refunded_through_the_platforms_processor(): void
    {
        $checkout = $this->held();
        $this->paid($checkout)->assertOk();
        $payment = $this->withoutScope(fn () => Payment::query()->where('method', 'shopify')->sole());

        $this->actingForOrganization($this->harbour);
        $this->expectException(PaymentException::class);
        $this->expectExceptionMessage('Refund it in Shopify');

        $this->app->make(PaymentService::class)->refund($payment, Money::of(1000, 'EUR'));
    }

    public function test_a_cancelled_shopify_booking_says_what_to_refund_in_shopify(): void
    {
        $checkout = $this->held();
        $this->paid($checkout)->assertOk();
        $reservation = $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id));

        $this->actingForOrganization($this->harbour);
        $this->app->make(ReservationService::class)->cancel($reservation, 'Guest cancelled', 'user', waiveFees: true);
        $this->app->make(TenantContext::class)->clear();

        $staff = $this->createUser($this->harbour, [RoleRegistry::RESERVATIONS_AGENT]);

        $this->actingAsUser($staff, $this->harbour)
            ->getJson('/api/v1/reservations/'.$reservation->getKey())
            ->assertOk()
            ->assertJsonPath('data.website_request.refund_on_store.amount.amount', $checkout->due_now_amount)
            ->assertJsonPath('data.website_request.refund_on_store.order_name', '#1042')
            ->assertJsonPath('data.website_request.refund_on_store.order_url', 'https://'.self::SHOP.'/admin/orders/'.crc32('#1042'));
    }

    public function test_a_refund_made_after_cancelling_lowers_what_is_still_due(): void
    {
        $checkout = $this->held();
        $this->paid($checkout)->assertOk();
        $reservation = $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id));

        $this->actingForOrganization($this->harbour);
        $this->app->make(ReservationService::class)->cancel($reservation, 'Guest cancelled', 'user', waiveFees: true);
        $this->app->make(TenantContext::class)->clear();

        $this->refunded(556, '50.00')->assertOk();

        $staff = $this->createUser($this->harbour, [RoleRegistry::RESERVATIONS_AGENT]);
        $this->actingAsUser($staff, $this->harbour)
            ->getJson('/api/v1/reservations/'.$reservation->getKey())
            ->assertJsonPath('data.website_request.refund_on_store.amount.amount', $checkout->due_now_amount - 5000);

        $this->refunded(557, number_format(($checkout->due_now_amount - 5000) / 100, 2, '.', ''))->assertOk();

        $this->actingAsUser($staff, $this->harbour)
            ->getJson('/api/v1/reservations/'.$reservation->getKey())
            ->assertJsonPath('data.website_request.refund_on_store', null);
    }

    public function test_a_refund_from_another_store_is_not_recorded(): void
    {
        $checkout = $this->held();
        $this->paid($checkout)->assertOk();

        // Another client's store, with its own app, names this store's order.
        $other = Website::query()->create([
            'slug' => 'other', 'name' => 'Other stays', 'kind' => Website::KIND_BRAND, 'status' => Website::STATUS_LIVE,
            'hold_minutes' => 30, 'request_hours' => 24, 'settings' => [],
        ]);
        WebsiteShopifyConnection::query()->create([
            'website_id' => $other->getKey(), 'shop_domain' => 'someone-else.myshopify.com', 'currency' => 'EUR',
            'client_id' => 'other-id', 'client_secret' => 'other-secret',
            'access_token' => 'shpat_other', 'status' => WebsiteShopifyConnection::STATUS_CONNECTED, 'connected_at' => now(),
        ]);

        $this->webhook('refunds/create', [
            'id' => 558,
            'order_id' => crc32('#1042'),
            'transactions' => [['kind' => 'refund', 'status' => 'success', 'amount' => '50.00', 'currency' => 'EUR']],
        ], secret: 'other-secret', shop: 'someone-else.myshopify.com')->assertOk();

        $this->assertSame(0, $this->withoutScope(fn () => Refund::query()->count()));
    }

    public function test_an_uninstalled_app_breaks_the_connection(): void
    {
        $this->webhook('app/uninstalled', ['id' => 1, 'name' => 'Harbour Homes', 'domain' => self::SHOP])->assertOk();

        $connection = WebsiteShopifyConnection::query()->sole();
        $this->assertSame(WebsiteShopifyConnection::STATUS_BROKEN, $connection->status);
        $this->assertNull($connection->access_token);
    }

    public function test_shopifys_privacy_requests_are_answered(): void
    {
        foreach (['customers/data_request', 'customers/redact', 'shop/redact'] as $topic) {
            $this->webhook($topic, ['shop_domain' => self::SHOP])->assertOk();
        }
    }

    // ------------------------------------------------------------------

    private function held(?CarbonImmutable $checkIn = null): WebsiteCheckout
    {
        $checkIn ??= CarbonImmutable::today()->addDays(30);

        $quote = (string) $this->postJson('/sites/brand/api/listings/'.$this->flat->slug.'/quote', [
            'check_in' => $checkIn->toDateString(), 'check_out' => $checkIn->addDays(3)->toDateString(), 'adults' => 2,
        ])->assertOk()->json('quote_id');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Test browser'])
            ->postJson('/sites/brand/api/checkouts', [
                'quote_id' => $quote, 'first_name' => 'Ana', 'last_name' => 'Costa', 'email' => 'ana@example.test',
                'phone' => '+351 912 345 678', 'accept_policies' => true,
            ])->assertCreated()->assertJsonPath('channel', 'shopify');

        return WebsiteCheckout::query()->latest()->firstOrFail();
    }

    private function paid(
        WebsiteCheckout $checkout,
        ?string $total = null,
        string $purpose = 'stay',
        string $name = '#1042',
        string $secret = 'client-secret',
        ?string $webhookId = null,
    ): TestResponse {
        return $this->webhook('orders/paid', [
            'id' => crc32($name),
            'name' => $name,
            'email' => 'ana@example.test',
            'financial_status' => 'paid',
            'total_price' => $total ?? number_format($checkout->due_now_amount / 100, 2, '.', ''),
            'currency' => 'EUR',
            'tags' => 'Habitat booking',
            'note_attributes' => [
                ['name' => 'habitat_checkout', 'value' => $checkout->getKey()],
                ['name' => 'habitat_purpose', 'value' => $purpose],
            ],
        ], $secret, $webhookId);
    }

    private function refunded(int $refundId, string $amount, string $orderName = '#1042'): TestResponse
    {
        return $this->webhook('refunds/create', [
            'id' => $refundId,
            'order_id' => crc32($orderName),
            'transactions' => [
                ['kind' => 'refund', 'status' => 'success', 'amount' => $amount, 'currency' => 'EUR'],
                ['kind' => 'refund', 'status' => 'failure', 'amount' => '999.00', 'currency' => 'EUR'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function webhook(string $topic, array $payload, string $secret = 'client-secret', ?string $webhookId = null, string $shop = self::SHOP): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/webhooks/shopify', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shop,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId ?? (string) Str::uuid(),
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
        ], $body);
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
