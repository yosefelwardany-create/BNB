<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Owners\Services\ClientAccounts;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Websites\Content\SiteContent;
use App\Domain\Websites\Models\Website;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where a site's pieces may be shown: the store's addresses, each home's
 * page on the store, and the snippets that place them. All of it is the
 * platform's to set; a client neither sees it nor changes it.
 */
class WebsiteEmbedSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS_MESSAGE = 'Use an address like https://www.example.com, with nothing after it.';

    private const STORE_PAGE_MESSAGE = "This page must be on one of the site's store addresses.";

    private User $operator;

    private Organization $account;

    private User $client;

    private Listing $home;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->createPlatformAdmin();
        ['organization' => $this->account, 'user' => $this->client] = $this->createClientOrganization(['name' => 'Harbour Homes']);

        $this->actingForOrganization($this->account);
        $property = Property::factory()->active()->create(['organization_id' => $this->account->getKey(), 'name' => 'Harbour Loft']);
        $this->home = Listing::factory()->published()->create([
            'organization_id' => $this->account->getKey(),
            'property_id' => $property->getKey(),
            'title' => 'Harbour Loft',
            'slug' => 'harbour-loft',
        ]);
        $this->app->make(ClientAccounts::class)->attachProperty($property);
        $this->app->make(TenantContext::class)->clear();

        $this->site = Website::query()->create([
            'slug' => 'harbour',
            'name' => 'Harbour stays',
            'kind' => Website::KIND_CLIENT,
            'organization_id' => $this->account->getKey(),
            'status' => Website::STATUS_LIVE,
            'settings' => ['contact_email' => 'stay@harbour.test'],
        ]);
        $this->site->forceFill(['draft_content' => SiteContent::starter($this->site)])->save();
        $this->site->listings()->create([
            'listing_id' => $this->home->getKey(),
            'organization_id' => $this->account->getKey(),
            'position' => 0,
        ]);
    }

    public function test_store_addresses_are_normalised(): void
    {
        $this->asOperator()
            ->patchJson($this->platform(), ['embed_origins' => ['https://Shop.Example.com/', 'https://www.example.com', 'https://shop.example.com']])
            ->assertOk()
            ->assertJsonPath('data.embed_origins', ['https://shop.example.com', 'https://www.example.com']);

        $this->assertSame(['https://shop.example.com', 'https://www.example.com'], $this->site->fresh()->embedOrigins());
    }

    public function test_a_store_address_is_https_with_nothing_after_it(): void
    {
        foreach (['http://x.com', 'https://x.com/shop', 'x.com', 'https://x.com?a=1', 'https://x.com#top', 'https://user@x.com'] as $address) {
            $this->asOperator()
                ->patchJson($this->platform(), ['embed_origins' => [$address]])
                ->assertUnprocessable()
                ->assertJsonPath('errors', ['embed_origins.0' => [self::ADDRESS_MESSAGE]]);
        }

        $this->assertSame([], $this->site->fresh()->embedOrigins());
    }

    public function test_at_most_ten_store_addresses(): void
    {
        $addresses = array_map(fn (int $i): string => "https://shop{$i}.example.com", range(1, 11));

        $this->asOperator()
            ->patchJson($this->platform(), ['embed_origins' => $addresses])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('embed_origins');
    }

    public function test_a_home_page_on_the_store_must_be_on_a_store_address(): void
    {
        $this->asOperator()->patchJson($this->platform(), ['embed_origins' => ['https://shop.example.com']])->assertOk();

        $this->asOperator()
            ->putJson($this->platform('listings'), ['listings' => [
                ['listing_id' => $this->home->getKey(), 'store_url' => 'https://www.shop.example.com/products/loft'],
            ]])
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['listings.0.store_url' => [self::STORE_PAGE_MESSAGE]]);

        $this->asOperator()
            ->putJson($this->platform('listings'), ['listings' => [
                ['listing_id' => $this->home->getKey(), 'store_url' => 'https://shop.example.com/products/loft'],
            ]])
            ->assertOk()
            ->assertJsonPath('data.listings.0.store_url', 'https://shop.example.com/products/loft');

        $this->asOperator()
            ->putJson($this->platform('listings'), ['listings' => [
                ['listing_id' => $this->home->getKey(), 'store_url' => null],
            ]])
            ->assertOk()
            ->assertJsonPath('data.listings.0.store_url', null);
    }

    public function test_a_client_neither_sees_nor_changes_store_settings(): void
    {
        $this->asOperator()->patchJson($this->platform(), ['embed_origins' => ['https://shop.example.com']])->assertOk();
        $this->asOperator()->putJson($this->platform('listings'), ['listings' => [
            ['listing_id' => $this->home->getKey(), 'store_url' => 'https://shop.example.com/products/loft'],
        ]])->assertOk();

        $mine = $this->asClient()->getJson($this->portal())->assertOk()->json('data');
        $this->assertArrayNotHasKey('embed_origins', $mine);
        $this->assertArrayNotHasKey('embed', $mine);
        $this->assertArrayNotHasKey('store_url', $mine['listings'][0]);

        $this->asClient()->patchJson($this->portal(), ['embed_origins' => ['https://evil.example']])->assertOk();
        $this->asClient()->putJson($this->portal('listings'), ['listings' => [
            ['listing_id' => $this->home->getKey(), 'is_featured' => true, 'store_url' => 'https://evil.example/x'],
        ]])->assertOk();

        $site = $this->site->fresh();
        $this->assertSame(['https://shop.example.com'], $site->embedOrigins());
        $this->assertSame('https://shop.example.com/products/loft', $site->listings()->sole()->store_url);
        $this->assertTrue($site->listings()->sole()->is_featured);
    }

    public function test_the_snippets_name_the_site_and_each_home(): void
    {
        $embed = $this->asOperator()->getJson($this->platform())->assertOk()->json('data.embed');

        $this->assertSame('<script src="'.url('/sites/embed.js').'" defer></script>', $embed['script']);
        $this->assertSame('<div data-habitat-site="harbour" data-habitat-piece="search"></div>', $embed['search']);
        $this->assertSame('<div data-habitat-site="harbour" data-habitat-piece="homes"></div>', $embed['homes']);
        $this->assertSame([[
            'listing_id' => $this->home->getKey(),
            'title' => 'Harbour Loft',
            'tag' => '<div data-habitat-site="harbour" data-habitat-piece="book" data-habitat-home="harbour-loft"></div>',
        ]], $embed['book']);
    }

    // ------------------------------------------------------------------

    private function platform(string $path = ''): string
    {
        return '/api/v1/platform/websites/'.$this->site->getKey().($path === '' ? '' : '/'.$path);
    }

    private function portal(string $path = ''): string
    {
        return '/api/v1/portal/owner/websites/'.$this->site->getKey().($path === '' ? '' : '/'.$path);
    }

    private function asOperator(): static
    {
        return $this->actingAs($this->operator->fresh(), 'sanctum');
    }

    private function asClient(): static
    {
        $this->app->make(AccessControl::class)->flushMemo();

        return $this->actingAsUser($this->client->fresh(), $this->account);
    }
}
