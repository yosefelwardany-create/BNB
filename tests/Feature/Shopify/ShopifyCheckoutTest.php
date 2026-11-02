<?php

declare(strict_types=1);

namespace Tests\Feature\Shopify;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Payments\Models\PaymentSchedule;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Domain\Websites\Models\WebsiteShopifyConnection;
use App\Domain\Websites\Models\WebsiteShopifyOrder;
use App\Domain\Websites\Services\WebsitePayments;
use App\Mail\Websites\BookingRequested;
use App\Mail\Websites\NewWebsiteBooking;
use App\Mail\Websites\RequestAcceptedPayOnStore;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Websites\BuildsSites;
use Tests\TestCase;

/**
 * A booking on a site connected to the client's Shopify store: Habitat holds
 * the nights and makes a one-off Shopify order for the stay at its price;
 * the guest pays it on the store's own checkout.
 */
class ShopifyCheckoutTest extends TestCase
{
    use BuildsSites;
    use RefreshDatabase;

    private const SHOP = 'harbour-homes.myshopify.com';

    private const INVOICE = 'https://harbour-homes.myshopify.com/12345/invoices/abc123';

    private Organization $harbour;

    private Website $site;

    private Listing $flat;

    /** Whether the store refuses to make orders. */
    private bool $storeFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        // No Stripe at all: the site takes payment on Shopify alone.
        config([
            'services.stripe.key' => null, 'services.stripe.secret' => null, 'services.stripe.webhook_secret' => null,
            'services.shopify.client_id' => 'client-id', 'services.shopify.client_secret' => 'client-secret', 'services.shopify.api_version' => '2026-10',
            'mail.default' => 'array',
        ]);
        Mail::fake();
        $this->fakeShopify();

        $this->harbour = $this->createOrganization(['name' => 'Harbour Homes', 'base_currency' => 'EUR']);

        $this->site = Website::query()->create([
            'slug' => 'brand', 'name' => 'Stay With Us', 'kind' => Website::KIND_BRAND, 'status' => Website::STATUS_LIVE,
            'hold_minutes' => 30, 'request_hours' => 24,
            'settings' => ['contact_email' => 'stay@harbour.test'],
            'embed_origins' => ['https://harbour-homes.myshopify.com', 'https://www.harbourhomes.com'],
        ]);

        $this->flat = $this->listing($this->harbour, 'Sea View Flat', ['instant_book' => true]);
        $this->show($this->flat);

        WebsiteShopifyConnection::query()->create([
            'website_id' => $this->site->getKey(), 'shop_domain' => self::SHOP, 'shop_name' => 'Harbour Homes', 'currency' => 'EUR',
            'domains' => ['https://www.harbourhomes.com'], 'access_token' => 'shpat_store', 'scopes' => 'write_draft_orders,read_orders',
            'status' => WebsiteShopifyConnection::STATUS_CONNECTED, 'connected_at' => now(),
        ]);

        $this->app->make(TenantContext::class)->clear();
    }

    public function test_a_booking_on_a_connected_site_goes_to_the_stores_checkout(): void
    {
        $this->get('/sites/brand/stay/'.$this->flat->slug)->assertOk()->assertSee('data-booking-box', false);

        $checkIn = CarbonImmutable::today()->addDays(30);
        $quote = $this->quote($this->flat, $checkIn, $checkIn->addDays(3));

        // No card form: the guest pays on the store's checkout.
        $this->get('/sites/brand/checkout/'.$quote)
            ->assertOk()
            ->assertDontSee('js.stripe.com', false)
            ->assertDontSee('data-payment', false)
            ->assertSee('Next you pay on our store');

        $response = $this->checkout($quote)
            ->assertCreated()
            ->assertJsonPath('channel', 'shopify')
            ->assertJsonPath('checkout_url', self::INVOICE)
            ->assertJsonPath('status', WebsiteCheckout::STATUS_HOLDING);

        $checkout = WebsiteCheckout::query()->sole();
        $this->assertSame('shopify', $checkout->payment_channel);
        $this->assertSame($checkout->url(), $response->json('booking_url'));

        $order = WebsiteShopifyOrder::query()->sole();
        $this->assertSame(WebsiteShopifyOrder::PURPOSE_STAY, $order->purpose);
        $this->assertSame($checkout->due_now_amount, $order->amount);
        $this->assertSame(self::INVOICE, $order->invoice_url);

        // The guest's page waits for the store's checkout.
        $this->get($checkout->url())->assertOk()->assertSee(self::INVOICE, false)->assertSee('Pay on our store');
    }

    public function test_the_shopify_order_carries_the_stay_price_reference_and_tag(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->quote($this->flat, $checkIn, $checkIn->addDays(3)))->assertCreated();

        $checkout = WebsiteCheckout::query()->sole();
        $reservation = $this->withoutScope(fn () => Reservation::query()->findOrFail($checkout->reservation_id));

        Http::assertSent(function (Request $request) use ($checkout, $checkIn, $reservation): bool {
            if (! str_contains((string) ($request->data()['query'] ?? ''), 'draftOrderCreate')) {
                return false;
            }

            $input = $request['variables']['input'];
            $line = $input['lineItems'][0];

            return $request->hasHeader('X-Shopify-Access-Token', 'shpat_store')
                && $line['title'] === sprintf('Sea View Flat · %s–%s · 2 guests', $checkIn->format('j M'), $checkIn->addDays(3)->format('j M Y'))
                && $line['quantity'] === 1
                && $line['originalUnitPriceWithCurrency'] === ['amount' => number_format($checkout->due_now_amount / 100, 2, '.', ''), 'currencyCode' => 'EUR']
                && $line['taxable'] === false
                && $line['requiresShipping'] === false
                && $input['email'] === 'ana@example.test'
                && $input['tags'] === ['Habitat booking']
                && $input['taxExempt'] === true
                && $input['note'] === 'Booking '.$reservation->confirmation_code
                && in_array(['key' => 'habitat_checkout', 'value' => $checkout->getKey()], $input['customAttributes'], true);
        });
    }

    public function test_a_deposit_site_charges_the_deposit_then_the_balance(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(90);
        $this->checkout($this->quote($this->flat, $checkIn, $checkIn->addDays(3)))->assertCreated();

        $checkout = WebsiteCheckout::query()->sole();
        $this->assertLessThan($checkout->total_amount, $checkout->due_now_amount);
        $this->assertSame($checkout->due_now_amount, WebsiteShopifyOrder::query()->sole()->amount);

        // Later: the stay is confirmed, and the rest falls due with no card to take it.
        $checkout->forceFill(['status' => WebsiteCheckout::STATUS_CONFIRMED])->save();
        $this->withoutScope(fn () => PaymentSchedule::query()->where('reservation_id', $checkout->reservation_id)->where('sequence', 1)->update(['status' => 'paid']));
        $this->withoutScope(fn () => PaymentSchedule::query()->where('reservation_id', $checkout->reservation_id)->where('sequence', '>', 1)
            ->update(['due_on' => CarbonImmutable::today()->toDateString(), 'auto_charge' => false]));

        $this->postJson($checkout->url().'/pay-balance')
            ->assertOk()
            ->assertJsonPath('checkout_url', self::INVOICE);

        $balance = WebsiteShopifyOrder::query()->where('purpose', WebsiteShopifyOrder::PURPOSE_BALANCE)->sole();
        $this->assertSame($checkout->total_amount - $checkout->due_now_amount, $balance->amount);

        // Asked again, the same order.
        $this->postJson($checkout->url().'/pay-balance')->assertOk();
        $this->assertSame(1, WebsiteShopifyOrder::query()->where('purpose', WebsiteShopifyOrder::PURPOSE_BALANCE)->count());
    }

    public function test_a_request_is_sent_without_paying_and_accepted_with_a_pay_link(): void
    {
        $this->withoutScope(fn () => $this->flat->property->forceFill(['instant_book' => false])->save());

        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->quote($this->flat, $checkIn, $checkIn->addDays(3)))
            ->assertCreated()
            ->assertJsonPath('status', WebsiteCheckout::STATUS_REQUESTED)
            ->assertJsonMissingPath('checkout_url');

        $checkout = WebsiteCheckout::query()->sole();
        $this->assertSame(0, WebsiteShopifyOrder::query()->count());
        Mail::assertQueued(BookingRequested::class);
        Mail::assertQueued(NewWebsiteBooking::class);

        $this->app->make(WebsitePayments::class)->accept($checkout->fresh());

        $checkout = $checkout->fresh();
        $this->assertSame(WebsiteCheckout::STATUS_HOLDING, $checkout->status);
        $this->assertEqualsWithDelta(now()->addHours(24)->getTimestamp(), $checkout->hold_expires_at->getTimestamp(), 5);
        $this->assertSame(self::INVOICE, WebsiteShopifyOrder::query()->sole()->invoice_url);
        Mail::assertQueued(RequestAcceptedPayOnStore::class, fn (RequestAcceptedPayOnStore $mail): bool => $mail->payUrl === self::INVOICE);
    }

    public function test_a_lapsed_hold_deletes_its_shopify_order(): void
    {
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->quote($this->flat, $checkIn, $checkIn->addDays(3)))->assertCreated();
        $order = WebsiteShopifyOrder::query()->sole();

        $this->travel(31)->minutes();
        $this->artisan('websites:release-expired-holds')->assertSuccessful();

        $this->assertSame(WebsiteCheckout::STATUS_EXPIRED, WebsiteCheckout::query()->sole()->status);
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['query'] ?? ''), 'draftOrderDelete')
            && $request['variables']['input']['id'] === $order->draft_order_id);
        $this->assertSame(WebsiteShopifyOrder::STATUS_DELETED, $order->fresh()->status);
    }

    public function test_a_site_without_a_working_store_keeps_stripe(): void
    {
        WebsiteShopifyConnection::query()->update(['status' => WebsiteShopifyConnection::STATUS_BROKEN]);

        // Without Stripe either, nobody books online.
        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->quote($this->flat, $checkIn, $checkIn->addDays(3)))->assertNotFound();
        $this->assertSame(0, WebsiteShopifyOrder::query()->count());
    }

    public function test_a_store_that_cannot_make_the_order_gives_the_nights_back(): void
    {
        $this->storeFails = true;

        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->checkout($this->quote($this->flat, $checkIn, $checkIn->addDays(3)))->assertStatus(503);

        $this->assertSame(WebsiteCheckout::STATUS_FAILED, WebsiteCheckout::query()->sole()->status);
    }

    // ------------------------------------------------------------------

    private function fakeShopify(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($this->storeFails) {
                return Http::response(['errors' => [['message' => 'Internal error']]], 500);
            }

            if ($request->url() !== 'https://'.self::SHOP.'/admin/api/2026-10/graphql.json') {
                return null;
            }

            static $next = 1000;

            if (str_contains((string) ($request->data()['query'] ?? ''), 'draftOrderDelete')) {
                return Http::response(['data' => ['draftOrderDelete' => ['deletedId' => $request['variables']['input']['id'], 'userErrors' => []]]]);
            }

            return Http::response(['data' => ['draftOrderCreate' => [
                'userErrors' => [],
                'draftOrder' => ['id' => 'gid://shopify/DraftOrder/'.$next++, 'name' => '#D'.$next, 'invoiceUrl' => self::INVOICE],
            ]]]);
        });
    }

    private function quote(Listing $listing, CarbonImmutable $checkIn, CarbonImmutable $checkOut): string
    {
        return (string) $this->postJson('/sites/brand/api/listings/'.$listing->slug.'/quote', [
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'adults' => 2,
        ])->assertOk()->json('quote_id');
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

    private function checkout(string $quoteId): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Test browser'])
            ->postJson('/sites/brand/api/checkouts', [
                'quote_id' => $quoteId,
                'first_name' => 'Ana',
                'last_name' => 'Costa',
                'email' => 'ana@example.test',
                'phone' => '+351 912 345 678',
                'accept_policies' => true,
            ]);
    }
}
