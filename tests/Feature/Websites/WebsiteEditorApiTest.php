<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\RoleRegistry;
use App\Domain\Websites\Content\SiteContent;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteImage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The page builder's side of the server: the draft it edits, saving it
 * safely, putting it live or throwing it away, the preview, and photos.
 */
class WebsiteEditorApiTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Organization $harbour;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->createPlatformAdmin();
        $this->harbour = $this->createOrganization(['name' => 'Harbour Homes']);

        $this->site = Website::query()->create([
            'slug' => 'brand',
            'name' => 'Stay With Us',
            'kind' => Website::KIND_BRAND,
            'status' => Website::STATUS_LIVE,
            'settings' => ['heading' => 'Sea & sun'],
        ]);
    }

    public function test_the_editor_opens_on_the_draft_with_everything_it_needs(): void
    {
        $this->asOperator()->getJson($this->editor())
            ->assertOk()
            ->assertJsonPath('data.version', 0)
            ->assertJsonPath('data.unpublished_changes', false)
            ->assertJsonPath('data.content.pages.0.blocks.0.type', 'hero')
            ->assertJsonPath('data.content.pages.0.blocks.0.fields.heading', 'Sea & sun')
            ->assertJsonCount(12, 'data.definitions')
            ->assertJsonPath('data.definitions.0.type', 'hero')
            ->assertJsonPath('data.definitions.0.fields.0.name', 'heading')
            ->assertJsonCount(3, 'data.themes')
            ->assertJsonCount(6, 'data.font_pairs')
            ->assertJsonCount(3, 'data.buttons')
            ->assertJsonPath('data.website.public_url', $this->site->url())
            ->assertJsonPath('data.images', [])
            ->assertJsonStructure(['data' => ['preview_url', 'photos', 'published_at']]);

        $this->assertStringContainsString('signature=', $this->asOperator()->getJson($this->editor())->json('data.preview_url'));
    }

    public function test_the_editor_is_told_the_homes_on_the_site(): void
    {
        $this->actingForOrganization($this->harbour);
        $property = Property::factory()->active()->create(['organization_id' => $this->harbour->getKey()]);
        $listing = Listing::factory()->published()->create(['organization_id' => $this->harbour->getKey(), 'property_id' => $property->getKey(), 'title' => 'Sea View Flat']);
        $this->app->make(TenantContext::class)->clear();
        $this->site->listings()->create(['listing_id' => $listing->getKey(), 'organization_id' => $this->harbour->getKey(), 'position' => 0]);

        $this->asOperator()->getJson($this->editor())
            ->assertOk()
            ->assertJsonPath('data.homes.0.listing_id', $listing->getKey())
            ->assertJsonPath('data.homes.0.title', 'Sea View Flat');
    }

    public function test_a_preview_address_is_given_for_any_page_of_the_draft(): void
    {
        $about = SiteContent::newId('pg_');
        $content = $this->draftWith('Home heading');
        $content['pages'][] = ['id' => $about, 'kind' => 'page', 'title' => 'About', 'slug' => 'about', 'in_menu' => true, 'blocks' => [SiteContent::block('text', ['heading' => 'About heading'])]];
        $this->save($content, 0)->assertOk();

        $url = $this->asOperator()->getJson($this->editor('preview').'?page='.$about)->assertOk()->json('data.url');
        $this->get($url)->assertOk()->assertSee('About heading');

        $home = $this->asOperator()->getJson($this->editor('preview'))->json('data.url');
        $this->get($home)->assertOk()->assertSee('Home heading');
    }

    public function test_a_draft_saves_and_counts_its_version(): void
    {
        $this->save($this->draftWith('Draft hello'), 0)
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.unpublished_changes', true);

        $this->assertSame('Draft hello', $this->site->fresh()->draft_content['pages'][0]['blocks'][0]['fields']['heading']);

        // Guests still see what was there.
        $this->get('/sites/brand')->assertOk()->assertSee('Sea &amp; sun', false)->assertDontSee('Draft hello');
    }

    public function test_a_stale_draft_is_refused_and_nothing_is_lost(): void
    {
        $this->save($this->draftWith('First'), 0)->assertOk();

        $this->save($this->draftWith('Second'), 0)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Someone else changed this site since you opened it. Reload to see their changes.');

        $site = $this->site->fresh();
        $this->assertSame('First', $site->draft_content['pages'][0]['blocks'][0]['fields']['heading']);
        $this->assertSame(1, $site->draft_version);
    }

    public function test_a_saved_draft_comes_back_as_it_was_kept(): void
    {
        $content = $this->draftWith('Hello');
        $content['pages'][0]['blocks'][] = SiteContent::block('text', ['heading' => 'About', 'body' => '<p onclick="x()">Hi<script>alert(1)</script></p>']);

        $this->save($content, 0)
            ->assertOk()
            ->assertJsonPath('data.content.pages.0.blocks.0.fields.heading', 'Hello')
            ->assertJsonPath('data.content.pages.0.blocks.2.fields.body', '<p>Hi</p>');
    }

    public function test_an_invalid_draft_is_refused_by_its_path(): void
    {
        $this->save($this->draftWith(str_repeat('a', 121)), 0)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pages.0.blocks.0.fields.heading');

        $this->assertSame(0, $this->site->fresh()->draft_version);
    }

    public function test_publishing_puts_the_draft_live(): void
    {
        $this->save($this->draftWith('Draft hello'), 0)->assertOk();

        $this->publish(1)
            ->assertOk()
            ->assertJsonPath('data.unpublished_changes', false)
            ->assertJsonPath('data.content.pages.0.blocks.0.fields.heading', 'Draft hello');

        $this->assertNotNull($this->site->fresh()->content_published_at);
        $this->get('/sites/brand')->assertOk()->assertSee('Draft hello');
    }

    public function test_a_draft_that_moved_on_is_not_published(): void
    {
        $this->save($this->draftWith('First'), 0)->assertOk();
        $this->save($this->draftWith('Second'), 1)->assertOk();

        // The editor publishing still holds version 1: someone saved since.
        $this->publish(1)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Someone else changed this site since you opened it. Reload to see their changes.');

        $this->assertNull($this->site->fresh()->content_published_at);
        $this->asOperator()->postJson($this->editor('publish'))->assertUnprocessable()->assertJsonValidationErrors('version');
    }

    public function test_discarding_returns_the_draft_to_what_is_live(): void
    {
        $this->save($this->draftWith('Draft hello'), 0)->assertOk();

        $this->asOperator()->postJson($this->editor('discard'))
            ->assertOk()
            ->assertJsonPath('data.unpublished_changes', false)
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.content.pages.0.blocks.0.fields.heading', 'Sea & sun');
    }

    public function test_the_preview_shows_the_draft_only_when_signed(): void
    {
        $this->site->forceFill(['status' => Website::STATUS_DRAFT])->save();
        $this->save($this->draftWith('Draft hello'), 0)->assertOk();

        $url = $this->asOperator()->getJson($this->editor())->json('data.preview_url');

        $response = $this->get($url)
            ->assertOk()
            ->assertSee('Draft hello')
            ->assertSee('data-block-id', false);

        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->get(strtok($url, '?'))->assertForbidden();
        $this->get($url.'x')->assertForbidden();

        // A page of the draft by its id.
        $about = SiteContent::newId('pg_');
        $content = $this->draftWith('Draft hello');
        $content['pages'][] = ['id' => $about, 'kind' => 'page', 'title' => 'About', 'slug' => 'about', 'in_menu' => true, 'blocks' => [SiteContent::block('text', ['heading' => 'About heading'])]];
        $this->save($content, 1)->assertOk();

        $this->get($this->asOperator()->getJson($this->editor().'?page='.$about)->json('data.preview_url'))
            ->assertOk()
            ->assertSee('About heading');
    }

    public function test_images_upload_and_are_offered_to_the_editor(): void
    {
        Storage::fake('media');

        $ref = $this->asOperator()
            ->post($this->editor('images'), ['file' => UploadedFile::fake()->image('garden.jpg', 10, 10), 'alt' => 'The garden'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.alt', 'The garden')
            ->json('data.ref');

        $this->assertStringStartsWith('img:', $ref);

        $this->asOperator()
            ->post($this->editor('images'), ['file' => UploadedFile::fake()->create('menu.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $this->asOperator()->getJson($this->editor())
            ->assertOk()
            ->assertJsonPath('data.images.0.ref', $ref)
            ->assertJsonPath('data.images.0.alt', 'The garden');

        // A block can use it now.
        $this->save($this->draftWith('With a photo', ['image' => $ref]), 0)->assertOk();
    }

    public function test_an_image_in_use_is_not_deleted(): void
    {
        Storage::fake('media');
        $ref = $this->asOperator()
            ->post($this->editor('images'), ['file' => UploadedFile::fake()->image('garden.jpg', 10, 10)], ['Accept' => 'application/json'])
            ->json('data.ref');
        $id = substr($ref, 4);

        $this->save($this->draftWith('With a photo', ['image' => $ref]), 0)->assertOk();

        $this->asOperator()->deleteJson($this->editor('images/'.$id))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This photo is used on the site. Take it out of its blocks first.');

        $this->save($this->draftWith('Without'), 1)->assertOk();

        $this->asOperator()->deleteJson($this->editor('images/'.$id))->assertNoContent();
        $this->assertNull(WebsiteImage::query()->find($id));
    }

    public function test_a_new_site_starts_with_a_laid_out_home_page(): void
    {
        $id = $this->asOperator()
            ->postJson('/api/v1/platform/websites', ['kind' => 'brand', 'name' => 'New Stays', 'slug' => 'new-stays'])
            ->assertCreated()
            ->json('data.id');

        $blocks = $this->asOperator()->getJson("/api/v1/platform/websites/{$id}/editor")->json('data.content.pages.0.blocks');

        $this->assertSame(['hero', 'homes', 'text', 'contact'], array_column($blocks, 'type'));
    }

    public function test_only_the_platform_reaches_the_editor(): void
    {
        $admin = $this->createUser($this->harbour, [RoleRegistry::ORGANIZATION_ADMIN]);
        $client = $this->actingAsUser($admin, $this->harbour);

        $client->getJson($this->editor())->assertNotFound();
        $client->putJson($this->editor('draft'), ['content' => $this->draftWith('x'), 'version' => 0])->assertNotFound();
        $client->postJson($this->editor('publish'), ['version' => 0])->assertNotFound();
        $client->postJson($this->editor('discard'))->assertNotFound();

        $this->assertSame(0, $this->site->fresh()->draft_version);
    }

    // ------------------------------------------------------------------

    private function editor(string $path = ''): string
    {
        return '/api/v1/platform/websites/'.$this->site->getKey().'/editor'.($path === '' ? '' : '/'.$path);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function save(array $content, int $version): TestResponse
    {
        return $this->asOperator()->putJson($this->editor('draft'), ['content' => $content, 'version' => $version]);
    }

    /**
     * The site's own starting document, with its hero's heading changed.
     *
     * @param  array<string, mixed>  $hero
     * @return array<string, mixed>
     */
    private function draftWith(string $heading, array $hero = []): array
    {
        $content = SiteContent::fromSettings($this->site);
        $content['pages'][0]['blocks'][0]['fields'] = array_replace($content['pages'][0]['blocks'][0]['fields'], ['heading' => $heading], $hero);

        return $content;
    }

    private function publish(int $version): TestResponse
    {
        return $this->asOperator()->postJson($this->editor('publish'), ['version' => $version]);
    }

    private function asOperator(): static
    {
        return $this->actingAs($this->operator->fresh(), 'sanctum');
    }
}
