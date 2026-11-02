<?php

declare(strict_types=1);

namespace Tests\Feature\Shopify;

use App\Domain\Integrations\Exceptions\ShopifyRequestException;
use App\Domain\Integrations\Shopify\ShopifyClient;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteShopifyConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Talking to a client's store: Shopify's Admin GraphQL API, with the
 * store's own access token, at the version Habitat was written against.
 */
class ShopifyClientTest extends TestCase
{
    use RefreshDatabase;

    private WebsiteShopifyConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopify.client_id' => 'client-id', 'services.shopify.client_secret' => 'client-secret', 'services.shopify.api_version' => '2026-10']);
        Http::preventStrayRequests();

        $site = Website::query()->create([
            'slug' => 'harbour', 'name' => 'Harbour stays', 'kind' => Website::KIND_BRAND, 'status' => Website::STATUS_LIVE,
        ]);

        $this->connection = WebsiteShopifyConnection::query()->create([
            'website_id' => $site->getKey(),
            'shop_domain' => 'harbour-homes.myshopify.com',
            'shop_name' => 'Harbour Homes',
            'currency' => 'EUR',
            'domains' => ['https://www.harbourhomes.com'],
            'access_token' => 'shpat_secret',
            'scopes' => 'write_draft_orders,read_orders',
            'status' => WebsiteShopifyConnection::STATUS_CONNECTED,
            'connected_at' => now(),
        ]);
    }

    public function test_a_query_carries_the_token_to_the_versioned_endpoint(): void
    {
        Http::fake(['https://harbour-homes.myshopify.com/admin/api/2026-10/graphql.json' => Http::response(['data' => ['shop' => ['name' => 'Harbour Homes']]])]);

        $data = $this->app->make(ShopifyClient::class)->graphql($this->connection, 'query { shop { name } }');

        $this->assertSame(['shop' => ['name' => 'Harbour Homes']], $data);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Shopify-Access-Token', 'shpat_secret')
            && $request['query'] === 'query { shop { name } }');

        // Kept encrypted, and never shown.
        $this->assertNotSame('shpat_secret', $this->connection->getRawOriginal('access_token'));
        $this->assertArrayNotHasKey('access_token', $this->connection->toArray());
    }

    public function test_an_expired_token_is_refreshed_first(): void
    {
        $this->connection->forceFill(['refresh_token' => 'shprt_old', 'access_token_expires_at' => now()->subMinute()])->save();

        Http::fake([
            'https://harbour-homes.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_new', 'scope' => 'write_draft_orders,read_orders', 'refresh_token' => 'shprt_new', 'expires_in' => 3600,
            ]),
            'https://harbour-homes.myshopify.com/admin/api/2026-10/graphql.json' => Http::response(['data' => ['shop' => ['name' => 'Harbour Homes']]]),
        ]);

        $this->app->make(ShopifyClient::class)->graphql($this->connection, 'query { shop { name } }');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/admin/oauth/access_token')
            && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'shprt_old');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/graphql.json') && $request->hasHeader('X-Shopify-Access-Token', 'shpat_new'));

        $fresh = $this->connection->fresh();
        $this->assertSame('shpat_new', $fresh->access_token);
        $this->assertSame('shprt_new', $fresh->refresh_token);
        $this->assertTrue($fresh->access_token_expires_at->isFuture());
    }

    public function test_a_refused_token_breaks_the_connection(): void
    {
        Http::fake(['*' => Http::response(['errors' => '[API] Invalid API key or access token (unrecognized login or wrong password)'], 401)]);

        try {
            $this->app->make(ShopifyClient::class)->graphql($this->connection, 'query { shop { name } }');
            $this->fail('A refused token should throw.');
        } catch (ShopifyRequestException $e) {
            $this->assertSame(401, $e->status);
        }

        $fresh = $this->connection->fresh();
        $this->assertSame(WebsiteShopifyConnection::STATUS_BROKEN, $fresh->status);
        $this->assertNotNull($fresh->broken_at);
    }

    public function test_graphql_errors_are_an_error(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['message' => 'Field does not exist']]])]);

        $this->expectException(ShopifyRequestException::class);
        $this->expectExceptionMessage('Field does not exist');

        $this->app->make(ShopifyClient::class)->graphql($this->connection, 'query { nope }');
    }
}
