<?php

declare(strict_types=1);

namespace Tests\Feature\Shopify;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Owners\Services\ClientAccounts;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteShopifyConnection;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Store only": a client whose guests should find their homes on their own
 * Shopify store. The site's pages at the platform's address send visitors
 * there; the pieces on the store, the pop-up and a guest's booking pages
 * keep working.
 */
class StoreOnlyTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'harbour-homes.myshopify.com';

    private const STORE = 'https://www.harbourhomes.com';

    private User $operator;

    private Organization $account;

    private User $client;

    private Website $site;

    private Listing $home;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->createPlatformAdmin();
        ['organization' => $this->account, 'user' => $this->client] = $this->createClientOrganization(['name' => 'Harbour Homes', 'base_currency' => 'EUR']);

        $this->actingForOrganization($this->account);
        $property = Property::factory()->active()->create([
            'organization_id' => $this->account->getKey(), 'currency' => 'EUR', 'base_rate' => 10000,
            'cleaning_fee' => 0, 'max_occupancy' => 4, 'minimum_nights' => 1,
        ]);
        $this->home = Listing::factory()->published()->create([
            'organization_id' => $this->account->getKey(), 'property_id' => $property->getKey(), 'title' => 'Harbour Loft', 'currency' => 'EUR',
        ]);
        $this->app->make(ClientAccounts::class)->attachProperty($property);
        $this->app->make(TenantContext::class)->clear();

        $this->site = Website::query()->create([
            'slug' => 'harbour', 'name' => 'Harbour stays', 'kind' => Website::KIND_CLIENT,
            'organization_id' => $this->account->getKey(), 'status' => Website::STATUS_LIVE,
            'embed_origins' => ['https://'.self::SHOP, self::STORE],
        ]);
        $this->site->listings()->create(['listing_id' => $this->home->getKey(), 'organization_id' => $this->account->getKey(), 'position' => 0]);
    }

    public function test_store_only_sends_the_sites_pages_to_the_store(): void
    {
        $this->connect();

        $this->asOperator()->patchJson($this->platform(), ['store_only' => true])
            ->assertOk()
            ->assertJsonPath('data.store_only', true);

        $this->get('/sites/harbour')->assertStatus(302)->assertRedirect(self::STORE);
        $this->get('/sites/harbour/search?checkin=2027-01-10&checkout=2027-01-12')->assertStatus(302)->assertRedirect(self::STORE);
        $this->get('/sites/harbour/about')->assertStatus(302)->assertRedirect(self::STORE);

        // A home goes to its own page on the store, when it has one.
        $stay = '/sites/harbour/stay/'.$this->home->slug;
        $this->get($stay)->assertStatus(302)->assertRedirect(self::STORE);
        $this->site->listings()->update(['store_url' => self::STORE.'/pages/harbour-loft']);
        $this->get($stay)->assertStatus(302)->assertRedirect(self::STORE.'/pages/harbour-loft');

        // Nothing at the platform's address for search engines to list.
        $this->assertStringNotContainsString('<url>', $this->get('/sites/harbour/sitemap.xml')->assertOk()->getContent());

        // The client does not see the switch.
        $this->actingAs($this->client->fresh(), 'sanctum')
            ->getJson('/api/v1/portal/owner/websites/'.$this->site->getKey())
            ->assertOk()
            ->assertJsonMissingPath('data.store_only');

        // Off again, the pages are back.
        $this->asOperator()->patchJson($this->platform(), ['store_only' => false])->assertOk();
        $this->get('/sites/harbour')->assertOk();
    }

    public function test_store_only_keeps_pieces_checkout_and_booking_pages(): void
    {
        $this->connect();
        $this->site->forceFill(['store_only' => true])->save();

        $this->get('/sites/harbour/embed/search')->assertOk();
        $this->get('/sites/harbour/embed/homes')->assertOk();
        $this->get('/sites/harbour/embed/stay/'.$this->home->slug)->assertOk();
        $this->get('/sites/harbour/embed/book/'.$this->home->slug)->assertOk();
        $this->get('/sites/harbour/robots.txt')->assertOk();

        $checkIn = CarbonImmutable::today()->addDays(30);
        $this->getJson('/sites/harbour/api/listings/'.$this->home->slug.'/calendar')->assertOk();
        $quote = (string) $this->postJson('/sites/harbour/api/listings/'.$this->home->slug.'/quote', [
            'check_in' => $checkIn->toDateString(), 'check_out' => $checkIn->addDays(2)->toDateString(), 'adults' => 2,
        ])->assertOk()->json('quote_id');

        // The guest's own page: its brand and menu lead back to the store.
        $page = $this->get('/sites/harbour/checkout/'.$quote)->assertOk()->getContent();
        $this->assertStringContainsString('class="site-brand" href="'.self::STORE.'"', $page);
        $this->assertStringNotContainsString('href="'.$this->site->url('search').'"', $page);
    }

    public function test_store_only_needs_a_connected_store(): void
    {
        $this->asOperator()->patchJson($this->platform(), ['store_only' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Connect a Shopify store first.');

        $this->connect(WebsiteShopifyConnection::STATUS_PENDING);
        $this->asOperator()->patchJson($this->platform(), ['store_only' => true])->assertUnprocessable();

        $this->assertFalse((bool) $this->site->fresh()->store_only);
        $this->get('/sites/harbour')->assertOk();
    }

    public function test_a_broken_connection_keeps_the_pages_pointing_to_the_store(): void
    {
        $connection = $this->connect();
        $this->asOperator()->patchJson($this->platform(), ['store_only' => true])->assertOk();

        $connection->forceFill(['status' => WebsiteShopifyConnection::STATUS_BROKEN])->save();

        $this->get('/sites/harbour')->assertRedirect(self::STORE);
        // And it can still be turned off.
        $this->asOperator()->patchJson($this->platform(), ['store_only' => false])->assertOk();
        $this->get('/sites/harbour')->assertOk();
    }

    private function connect(string $status = WebsiteShopifyConnection::STATUS_CONNECTED): WebsiteShopifyConnection
    {
        return WebsiteShopifyConnection::query()->create([
            'website_id' => $this->site->getKey(), 'shop_domain' => self::SHOP, 'shop_name' => 'Harbour Homes', 'currency' => 'EUR',
            'domains' => [self::STORE], 'client_id' => 'client-id', 'client_secret' => 'client-secret',
            'access_token' => $status === WebsiteShopifyConnection::STATUS_CONNECTED ? 'shpat_store' : null,
            'status' => $status, 'connected_at' => $status === WebsiteShopifyConnection::STATUS_CONNECTED ? now() : null,
        ]);
    }

    private function platform(): string
    {
        return '/api/v1/platform/websites/'.$this->site->getKey();
    }

    private function asOperator(): static
    {
        return $this->actingAs($this->operator->fresh(), 'sanctum');
    }
}
