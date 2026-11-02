<?php

declare(strict_types=1);

namespace Tests\Feature\Shopify;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Owners\Services\ClientAccounts;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteShopifyConnection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Connecting a client's Shopify store to their booking site: someone with
 * admin rights on the store approves the platform's app, and Habitat keeps
 * the store's token, learns its name, currency and addresses, and asks to be
 * told when its orders are paid.
 */
class ShopifyConnectTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'harbour-homes.myshopify.com';

    private User $operator;

    private Organization $account;

    private User $client;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopify.client_id' => 'client-id', 'services.shopify.client_secret' => 'client-secret', 'services.shopify.api_version' => '2026-10']);
        // As in production: the admin application is served at /app by the
        // same server.
        config(['app.frontend_url' => config('app.url')]);
        Http::preventStrayRequests();

        $this->operator = $this->createPlatformAdmin();
        ['organization' => $this->account, 'user' => $this->client] = $this->createClientOrganization(['name' => 'Harbour Homes', 'base_currency' => 'EUR']);

        $this->actingForOrganization($this->account);
        $property = Property::factory()->active()->create(['organization_id' => $this->account->getKey(), 'currency' => 'EUR']);
        $home = Listing::factory()->published()->create(['organization_id' => $this->account->getKey(), 'property_id' => $property->getKey(), 'currency' => 'EUR']);
        $this->app->make(ClientAccounts::class)->attachProperty($property);
        $this->app->make(TenantContext::class)->clear();

        $this->site = Website::query()->create([
            'slug' => 'harbour', 'name' => 'Harbour stays', 'kind' => Website::KIND_CLIENT,
            'organization_id' => $this->account->getKey(), 'status' => Website::STATUS_LIVE,
        ]);
        $this->site->listings()->create(['listing_id' => $home->getKey(), 'organization_id' => $this->account->getKey(), 'position' => 0]);
    }

    public function test_the_install_link_names_the_app_scopes_return_address_and_state(): void
    {
        $url = $this->installLink();

        $this->assertStringStartsWith('https://'.self::SHOP.'/admin/oauth/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('write_draft_orders,read_orders', $query['scope']);
        $this->assertSame(url('/integrations/shopify/callback'), $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);

        $this->asOperator()
            ->postJson($this->platform('shopify/install-link'), ['shop' => 'evil.example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shop');
    }

    public function test_a_store_is_connected_and_its_addresses_may_show_the_pieces(): void
    {
        $this->fakeShopify();

        $this->get('/integrations/shopify/callback?'.http_build_query($this->approval($this->installLink())))
            ->assertRedirect($this->editor('connected'));

        $connection = WebsiteShopifyConnection::query()->sole();
        $this->assertSame($this->site->getKey(), $connection->website_id);
        $this->assertSame(WebsiteShopifyConnection::STATUS_CONNECTED, $connection->status);
        $this->assertSame('Harbour Homes', $connection->shop_name);
        $this->assertSame('EUR', $connection->currency);
        $this->assertSame(['https://www.harbourhomes.com'], $connection->domains);
        $this->assertSame('shpat_store', $connection->access_token);
        $this->assertNotSame('shpat_store', $connection->getRawOriginal('access_token'));

        $this->assertSame(['https://harbour-homes.myshopify.com', 'https://www.harbourhomes.com'], $this->site->fresh()->embedOrigins());

        $topics = Http::recorded(fn (Request $request): bool => str_contains((string) ($request->data()['query'] ?? ''), 'webhookSubscriptionCreate'))
            ->map(fn (array $pair): string => $pair[0]['variables']['topic'])->sort()->values()->all();
        $this->assertSame(['APP_UNINSTALLED', 'ORDERS_PAID', 'REFUNDS_CREATE'], $topics);
        Http::assertSent(fn (Request $request): bool => str_contains((string) ($request->data()['query'] ?? ''), 'webhookSubscriptionCreate')
            && $request['variables']['webhookSubscription']['uri'] === url('/webhooks/shopify'));

        $this->asOperator()->getJson($this->platform())
            ->assertOk()
            ->assertJsonPath('data.shopify.status', 'connected')
            ->assertJsonPath('data.shopify.shop_name', 'Harbour Homes')
            ->assertJsonPath('data.shopify.currency_matches', true)
            ->assertJsonPath('data.shopify.checkout_available', true);
    }

    public function test_a_tampered_or_replayed_or_late_approval_connects_nothing(): void
    {
        $this->fakeShopify();
        $approval = $this->approval($this->installLink());

        $tampered = ['shop' => 'other-store.myshopify.com'] + $approval;
        $this->get('/integrations/shopify/callback?'.http_build_query($tampered))->assertRedirect($this->editor('failed'));
        $this->assertSame(0, WebsiteShopifyConnection::query()->count());

        $this->get('/integrations/shopify/callback?'.http_build_query($approval))->assertRedirect($this->editor('connected'));
        WebsiteShopifyConnection::query()->delete();
        $this->get('/integrations/shopify/callback?'.http_build_query($approval))->assertRedirect($this->editor('failed'));
        $this->assertSame(0, WebsiteShopifyConnection::query()->count());

        $late = $this->approval($this->installLink());
        $this->travel(2)->hours();
        $this->get('/integrations/shopify/callback?'.http_build_query($late))->assertRedirect($this->editor('failed'));
        $this->assertSame(0, WebsiteShopifyConnection::query()->count());
    }

    public function test_the_state_is_safe_in_an_address_whatever_shopify_encodes(): void
    {
        parse_str((string) parse_url($this->installLink(), PHP_URL_QUERY), $link);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32,}$/', (string) $link['state']);
    }

    public function test_an_approval_on_another_store_than_the_one_typed_connects_nothing(): void
    {
        $this->fakeShopify();
        $approval = $this->approval($this->installLink(), shop: 'someone-else.myshopify.com');

        $this->get('/integrations/shopify/callback?'.http_build_query($approval))->assertRedirect($this->editor('failed'));

        $this->assertSame(0, WebsiteShopifyConnection::query()->count());
    }

    public function test_a_store_that_will_not_send_its_orders_is_not_connected(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/admin/oauth/access_token')) {
                return Http::response(['access_token' => 'shpat_store', 'scope' => 'write_draft_orders,read_orders']);
            }

            if (str_contains((string) ($request->data()['query'] ?? ''), 'webhookSubscriptionCreate')) {
                return Http::response(['data' => ['webhookSubscriptionCreate' => ['userErrors' => [['field' => ['topic'], 'message' => 'Protected customer data access is required']], 'webhookSubscription' => null]]]);
            }

            return Http::response(['data' => ['shop' => ['name' => 'Harbour Homes', 'currencyCode' => 'EUR', 'myshopifyDomain' => self::SHOP, 'primaryDomain' => ['url' => 'https://www.harbourhomes.com']]]]);
        });

        $this->get('/integrations/shopify/callback?'.http_build_query($this->approval($this->installLink())))
            ->assertRedirect($this->editor('failed'));

        $this->assertFalse(WebsiteShopifyConnection::query()->where('status', WebsiteShopifyConnection::STATUS_CONNECTED)->exists());
        $this->asOperator()->getJson($this->platform())->assertOk()->assertJsonPath('data.shopify.checkout_available', false);
    }

    public function test_a_store_selling_in_another_currency_takes_no_bookings(): void
    {
        $this->fakeShopify(currency: 'USD');

        $this->get('/integrations/shopify/callback?'.http_build_query($this->approval($this->installLink())))
            ->assertRedirect($this->editor('connected'));

        $this->asOperator()->getJson($this->platform())
            ->assertOk()
            ->assertJsonPath('data.shopify.currency', 'USD')
            ->assertJsonPath('data.shopify.currency_matches', false)
            ->assertJsonPath('data.shopify.checkout_available', false);
    }

    public function test_disconnecting_forgets_the_store(): void
    {
        $this->fakeShopify();
        $this->get('/integrations/shopify/callback?'.http_build_query($this->approval($this->installLink())));

        $this->asOperator()->deleteJson($this->platform('shopify'))
            ->assertOk()
            ->assertJsonPath('data.shopify.status', 'disconnected')
            ->assertJsonPath('data.shopify.checkout_available', false);

        $connection = WebsiteShopifyConnection::query()->sole();
        $this->assertNull($connection->access_token);
        $this->assertNull($connection->refresh_token);
    }

    public function test_a_client_sees_nothing_of_shopify(): void
    {
        $this->app->make(AccessControl::class)->flushMemo();

        $site = $this->actingAsUser($this->client->fresh(), $this->account)
            ->getJson('/api/v1/portal/owner/websites/'.$this->site->getKey())
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('shopify', $site);

        $this->actingAsUser($this->client->fresh(), $this->account)
            ->postJson('/api/v1/platform/websites/'.$this->site->getKey().'/shopify/install-link', ['shop' => self::SHOP])
            ->assertNotFound();
    }

    // ------------------------------------------------------------------

    private function installLink(): string
    {
        return (string) $this->asOperator()
            ->postJson($this->platform('shopify/install-link'), ['shop' => self::SHOP])
            ->assertOk()
            ->json('url');
    }

    /**
     * What Shopify sends back to the callback once the store approves:
     * the state it was given, a code, and its signature over all of it.
     *
     * @return array<string, string>
     */
    private function approval(string $installLink, ?string $shop = null): array
    {
        parse_str((string) parse_url($installLink, PHP_URL_QUERY), $link);

        $query = ['code' => 'code-123', 'host' => base64_encode(self::SHOP.'/admin'), 'shop' => $shop ?? self::SHOP, 'state' => (string) $link['state'], 'timestamp' => (string) now()->getTimestamp()];
        ksort($query);
        // As Shopify's own libraries sign it: the URL-encoded query.
        $query['hmac'] = hash_hmac('sha256', http_build_query($query), 'client-secret');

        return $query;
    }

    private function fakeShopify(string $currency = 'EUR'): void
    {
        Http::fake(function (Request $request) use ($currency) {
            if (str_ends_with($request->url(), '/admin/oauth/access_token')) {
                return Http::response(['access_token' => 'shpat_store', 'scope' => 'write_draft_orders,read_orders']);
            }

            if (str_contains((string) ($request->data()['query'] ?? ''), 'webhookSubscriptionCreate')) {
                return Http::response(['data' => ['webhookSubscriptionCreate' => ['userErrors' => [], 'webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1']]]]);
            }

            return Http::response(['data' => ['shop' => [
                'name' => 'Harbour Homes',
                'currencyCode' => $currency,
                'myshopifyDomain' => self::SHOP,
                'primaryDomain' => ['url' => 'https://www.harbourhomes.com'],
            ]]]);
        });
    }

    private function editor(string $outcome): string
    {
        return url('/app/websites/'.$this->site->getKey().'/edit?shopify='.$outcome);
    }

    private function platform(string $path = ''): string
    {
        return '/api/v1/platform/websites/'.$this->site->getKey().($path === '' ? '' : '/'.$path);
    }

    private function asOperator(): static
    {
        return $this->actingAs($this->operator->fresh(), 'sanctum');
    }
}
