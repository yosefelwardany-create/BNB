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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A client edits and publishes their own client site from the client portal,
 * and nothing else: the address, the booking rules, whether it is online and
 * whose it is stay with the platform, and the portal stays read-only for
 * everything but the site.
 */
class ClientWebsiteEditorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $account;

    private User $client;

    private Listing $home;

    private Website $site;

    private Website $otherSite;

    private Website $brand;

    private Listing $otherHome;

    protected function setUp(): void
    {
        parent::setUp();

        ['organization' => $this->account, 'user' => $this->client] = $this->createClientOrganization(['name' => 'Harbour Homes']);
        $this->home = $this->home($this->account, 'Harbour Loft');

        ['organization' => $other] = $this->createClientOrganization(['name' => 'Hill Houses']);
        $this->otherHome = $this->home($other, 'Hill House');

        $this->app->make(TenantContext::class)->clear();

        $this->site = $this->site('harbour', Website::KIND_CLIENT, $this->account);
        $this->otherSite = $this->site('hill', Website::KIND_CLIENT, $other);
        $this->brand = $this->site('brand', Website::KIND_BRAND, null);
    }

    public function test_a_client_lists_their_own_sites(): void
    {
        $this->asClient()->getJson('/api/v1/portal/owner/websites')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->site->getKey())
            ->assertJsonPath('data.0.name', 'Harbour stays')
            ->assertJsonPath('data.0.public_url', $this->site->url());
    }

    public function test_a_client_edits_publishes_and_discards_their_site(): void
    {
        $content = $this->asClient()->getJson($this->editor())->assertOk()->json('data.content');
        $content['pages'][0]['blocks'][0]['fields']['heading'] = 'Our harbour homes';
        $hero = $content['pages'][0]['blocks'][0]['id'];

        $this->asClient()->putJson($this->editor('draft'), ['content' => $content, 'version' => 0])->assertOk()->assertJsonPath('data.version', 1);
        $this->asClient()->postJson($this->editor('render'), ['content' => $content, 'block' => $hero])->assertOk();
        $this->asClient()->getJson($this->editor('preview'))->assertOk();

        $this->asClient()->postJson($this->editor('publish'), ['version' => 1])->assertOk()->assertJsonPath('data.unpublished_changes', false);
        $this->get('/sites/harbour')->assertOk()->assertSee('Our harbour homes');

        $content['pages'][0]['blocks'][0]['fields']['heading'] = 'Not this one';
        $this->asClient()->putJson($this->editor('draft'), ['content' => $content, 'version' => 1])->assertOk();
        $this->asClient()->postJson($this->editor('discard'))->assertOk()
            ->assertJsonPath('data.content.pages.0.blocks.0.fields.heading', 'Our harbour homes');
    }

    public function test_another_accounts_site_and_the_brand_site_answer_not_found(): void
    {
        foreach ([$this->otherSite, $this->brand] as $site) {
            $base = '/api/v1/portal/owner/websites/'.$site->getKey();

            $this->asClient()->getJson($base)->assertNotFound();
            $this->asClient()->getJson($base.'/editor')->assertNotFound();
            $this->asClient()->putJson($base.'/editor/draft', ['content' => SiteContent::starter($site), 'version' => 0])->assertNotFound();
            $this->asClient()->postJson($base.'/editor/publish', ['version' => 0])->assertNotFound();
            $this->asClient()->patchJson($base, ['name' => 'Taken'])->assertNotFound();

            $this->assertSame(0, (int) $site->fresh()->draft_version);
            $this->assertNotSame('Taken', $site->fresh()->name);
        }
    }

    public function test_a_client_changes_the_details_but_not_the_address_rules_or_status(): void
    {
        $this->asClient()->patchJson($this->details(), [
            'name' => 'Harbour Homes by the sea',
            'settings' => ['contact_email' => 'stay@harbour.test', 'contact_phone' => '+351 210 000 111'],
            'slug' => 'taken-over',
            'hold_minutes' => 120,
            'request_hours' => 72,
            'status' => Website::STATUS_DRAFT,
            'organization_id' => $this->otherSite->organization_id,
        ])->assertOk()->assertJsonPath('data.name', 'Harbour Homes by the sea');

        $site = $this->site->fresh();
        $this->assertSame('Harbour Homes by the sea', $site->name);
        $this->assertSame('stay@harbour.test', $site->setting('contact_email'));
        $this->assertSame('harbour', $site->slug);
        $this->assertSame(Website::STATUS_LIVE, $site->status);
        $this->assertSame($this->account->getKey(), $site->organization_id);
        $this->assertNotSame(120, (int) $site->hold_minutes);
    }

    public function test_a_client_logo_cannot_be_svg_and_social_links_must_be_https(): void
    {
        Storage::fake('media');
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->asClient()->post($this->details('media'), ['kind' => 'logo', 'file' => $svg], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->asClient()->post($this->details('media'), ['kind' => 'hero', 'file' => UploadedFile::fake()->image('hero.png', 10, 10)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->asClient()->post($this->details('media'), ['kind' => 'logo', 'file' => UploadedFile::fake()->image('logo.png', 10, 10)], ['Accept' => 'application/json'])->assertOk();

        $this->asClient()->patchJson($this->details(), ['settings' => ['instagram_url' => 'http://instagram.com/harbour']])
            ->assertUnprocessable()->assertJsonValidationErrors('settings.instagram_url');
        $this->asClient()->patchJson($this->details(), ['settings' => ['facebook_url' => 'javascript:alert(1)']])
            ->assertUnprocessable()->assertJsonValidationErrors('settings.facebook_url');
        $this->asClient()->patchJson($this->details(), ['settings' => ['instagram_url' => 'https://instagram.com/harbour']])->assertOk();
    }

    public function test_a_client_chooses_only_their_own_ready_homes(): void
    {
        $this->asClient()->getJson($this->details('listing-options'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.listing_id', $this->home->getKey())
            ->assertJsonPath('data.0.ready', true)
            // Putting a home on sale is done by the management company.
            ->assertJsonPath('data.0.can_publish', false);

        $this->asClient()->putJson($this->details('listings'), ['listings' => [['listing_id' => $this->home->getKey(), 'is_featured' => true]]])
            ->assertOk()
            ->assertJsonPath('data.listings.0.listing_id', $this->home->getKey());

        $this->asClient()->putJson($this->details('listings'), ['listings' => [['listing_id' => $this->otherHome->getKey()]]])
            ->assertUnprocessable();
    }

    public function test_every_other_portal_write_is_still_refused(): void
    {
        $this->asClient()->postJson('/api/v1/properties', ['name' => 'Sneaky'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is read-only. Changes to properties, calendars, bookings and settings are made by the management company.');

        // The platform's own editor of the same site is the platform's, and is
        // not there at all for anyone else.
        $this->asClient()->putJson('/api/v1/platform/websites/'.$this->site->getKey().'/editor/draft', ['content' => SiteContent::starter($this->site), 'version' => 0])
            ->assertNotFound();

        $this->assertSame(0, (int) $this->site->fresh()->draft_version);
    }

    // ------------------------------------------------------------------

    private function editor(string $path = ''): string
    {
        return $this->details('editor').($path === '' ? '' : '/'.$path);
    }

    private function details(string $path = ''): string
    {
        return '/api/v1/portal/owner/websites/'.$this->site->getKey().($path === '' ? '' : '/'.$path);
    }

    private function asClient(): static
    {
        $this->app->make(AccessControl::class)->flushMemo();

        return $this->actingAsUser($this->client->fresh(), $this->account);
    }

    private function home(Organization $account, string $name): Listing
    {
        $this->actingForOrganization($account);
        $property = Property::factory()->active()->create(['organization_id' => $account->getKey(), 'name' => $name]);
        $listing = Listing::factory()->published()->create(['organization_id' => $account->getKey(), 'property_id' => $property->getKey(), 'title' => $name]);
        $this->app->make(ClientAccounts::class)->attachProperty($property);

        return $listing;
    }

    private function site(string $slug, string $kind, ?Organization $account): Website
    {
        $site = Website::query()->create([
            'slug' => $slug,
            'name' => ucfirst($slug).' stays',
            'kind' => $kind,
            'organization_id' => $account?->getKey(),
            'status' => Website::STATUS_LIVE,
            'hold_minutes' => 30,
            'settings' => ['contact_email' => $slug.'@stay.test'],
        ]);
        $site->forceFill(['draft_content' => SiteContent::starter($site)])->save();

        return $site;
    }
}
