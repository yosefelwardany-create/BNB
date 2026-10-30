<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Listings\Models\Listing;
use App\Domain\Organization\Models\Organization;
use App\Domain\Reviews\Models\Review;
use App\Domain\Websites\Blocks\BlockRegistry;
use App\Domain\Websites\Content\SiteContent;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteImage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A site's home page and its own pages, built from the published document's
 * blocks in its theme and style. The draft never reaches a guest.
 */
class SiteContentPagesTest extends TestCase
{
    use BuildsSites;
    use RefreshDatabase;

    private Organization $harbour;

    private Website $site;

    private Listing $flat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->harbour = $this->createOrganization(['name' => 'Harbour Homes', 'base_currency' => 'EUR']);

        $this->site = Website::query()->create([
            'slug' => 'brand',
            'name' => 'Stay With Us',
            'kind' => Website::KIND_BRAND,
            'status' => Website::STATUS_LIVE,
            'settings' => ['heading' => 'Sea & sun', 'contact_email' => 'hello@stay.test', 'contact_phone' => '+351 210 000 000'],
        ]);

        $this->flat = $this->listing($this->harbour, 'Sea View Flat');
        $this->show($this->flat);

        $this->app->make(TenantContext::class)->clear();
    }

    public function test_a_stage_one_site_without_a_document_looks_as_before(): void
    {
        $this->get('/sites/brand')
            ->assertOk()
            ->assertSee('Sea &amp; sun', false)
            ->assertSee('Sea View Flat')
            ->assertSee('sites/themes/coastal.css', false)
            ->assertSee('name="checkin"', false);
    }

    public function test_the_home_page_is_built_from_the_published_document(): void
    {
        $this->site->forceFill([
            'content' => $this->document([SiteContent::block('hero', ['heading' => 'Published hello'])]),
            'draft_content' => $this->document([SiteContent::block('hero', ['heading' => 'Draft hello'])]),
        ])->save();

        $this->get('/sites/brand')
            ->assertOk()
            ->assertSee('Published hello')
            ->assertDontSee('Draft hello')
            ->assertDontSee('data-block-id', false);
    }

    public function test_a_custom_page_has_its_own_address_and_menu_entry(): void
    {
        $about = $this->page('About us', 'about-us', [SiteContent::block('text', ['heading' => 'Our story', 'body' => '<p>We love guests.</p>'])]);
        $this->site->forceFill(['content' => $this->document([SiteContent::block('homes')], [$about])])->save();

        $this->get('/sites/brand/about-us')
            ->assertOk()
            ->assertSee('We love guests.')
            ->assertSee('<title>About us · Stay With Us</title>', false);

        $this->get('/sites/brand')->assertOk()->assertSee('href="'.$this->site->url('about-us').'"', false)->assertSee('About us');

        $about['in_menu'] = false;
        $this->site->forceFill(['content' => $this->document([SiteContent::block('homes')], [$about])])->save();

        $this->get('/sites/brand')->assertOk()->assertDontSee('href="'.$this->site->url('about-us').'"', false);
        $this->get('/sites/brand/about-us')->assertOk();
    }

    public function test_a_page_only_in_the_draft_is_not_found(): void
    {
        $this->site->forceFill([
            'content' => $this->document([SiteContent::block('homes')]),
            'draft_content' => $this->document([SiteContent::block('homes')], [$this->page('Secret', 'secret')]),
        ])->save();

        $this->get('/sites/brand/secret')->assertNotFound();
        $this->get('/sites/brand/no-such-page')->assertNotFound();
    }

    public function test_a_page_of_a_site_that_is_not_live_is_not_found(): void
    {
        $this->site->forceFill([
            'status' => Website::STATUS_DRAFT,
            'content' => $this->document([], [$this->page('About', 'about')]),
        ])->save();

        $this->get('/sites/brand/about')->assertNotFound();
    }

    public function test_existing_addresses_win_over_pages(): void
    {
        $this->get('/sites/brand/search')->assertOk()->assertSee('Sea View Flat');
        $this->get('/sites/brand/robots.txt')->assertOk();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function blocksAndThemes(): iterable
    {
        foreach (['coastal', 'classic', 'bold'] as $theme) {
            foreach (array_keys(BlockRegistry::all()) as $type) {
                yield "$type in $theme" => [$type, $theme];
            }
        }
    }

    #[DataProvider('blocksAndThemes')]
    public function test_every_block_renders_in_every_theme(string $type, string $theme): void
    {
        // Something for the blocks that show nothing without it.
        $this->review('Lovely stay, would come back.', 5);
        $image = WebsiteImage::query()->create(['website_id' => $this->site->getKey(), 'path' => 'websites/a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1]);
        $fields = $type === 'gallery' ? ['images' => ['img:'.$image->getKey()]] : [];

        $this->site->forceFill(['content' => ['theme' => $theme] + $this->document([SiteContent::block($type, $fields, 'tint')])])->save();

        $this->get('/sites/brand')
            ->assertOk()
            ->assertSee('block--'.$type, false)
            ->assertSee('bg--tint', false)
            ->assertSee('sites/themes/'.$theme.'.css', false)
            ->assertSee('theme-'.$theme, false);
    }

    public function test_blocks_show_what_they_were_given(): void
    {
        $this->site->forceFill(['content' => $this->document([
            SiteContent::block('faq', ['title' => 'Good to know', 'items' => [['question' => 'Pets?', 'answer' => 'Small ones are welcome.']]]),
            SiteContent::block('highlights', ['items' => [['icon' => 'wifi', 'text' => 'Fast Wi-Fi']]]),
            SiteContent::block('cta', ['text' => 'Book direct and save', 'button' => ['label' => 'See homes', 'kind' => 'search', 'target' => '']]),
            SiteContent::block('contact', ['intro' => 'We answer within the hour.']),
            SiteContent::block('homes', ['which' => 'chosen', 'chosen' => [$this->flat->getKey()]]),
        ])])->save();

        $this->get('/sites/brand')
            ->assertOk()
            ->assertSee('Good to know')
            ->assertSee('Pets?')
            ->assertSee('Small ones are welcome.')
            ->assertSee('Fast Wi-Fi')
            ->assertSee('Book direct and save')
            ->assertSee('href="'.$this->site->url('search').'"', false)
            ->assertSee('We answer within the hour.')
            ->assertSee('mailto:hello@stay.test', false)
            ->assertSee('Sea View Flat');
    }

    public function test_reviews_show_the_best_published_ones_above_the_rating_asked_for(): void
    {
        $this->review('Perfect in every way.', 5);
        $this->review('It was fine.', 3);
        $this->review('Hidden one.', 5, 'hidden');

        $this->site->forceFill(['content' => $this->document([SiteContent::block('reviews', ['min_rating' => '80', 'count' => '3'])])])->save();

        $this->get('/sites/brand')
            ->assertOk()
            ->assertSee('Perfect in every way.')
            ->assertDontSee('It was fine.')
            ->assertDontSee('Hidden one.');
    }

    public function test_a_gallery_or_reviews_with_nothing_to_show_leave_no_gap(): void
    {
        $this->site->forceFill(['content' => $this->document([SiteContent::block('gallery', ['title' => 'Our photos']), SiteContent::block('reviews')])])->save();

        $this->get('/sites/brand')->assertOk()->assertDontSee('block--gallery', false)->assertDontSee('block--reviews', false);
    }

    public function test_a_block_pointing_at_missing_things_still_renders_the_page(): void
    {
        $image = WebsiteImage::query()->create(['website_id' => $this->site->getKey(), 'path' => 'gone.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1]);
        $ref = 'img:'.$image->getKey();
        $image->delete();

        $this->site->forceFill(['content' => $this->document([
            SiteContent::block('text_image', ['heading' => 'Our garden', 'image' => $ref]),
            SiteContent::block('homes', ['which' => 'chosen', 'chosen' => ['01JC0000000000000000000000'], 'title' => 'Picked for you']),
            SiteContent::block('gallery', ['images' => [$ref, 'photo:01JC0000000000000000000000']]),
        ])])->save();

        $response = $this->get('/sites/brand')->assertOk()->assertSee('Our garden');

        $this->assertStringNotContainsString($ref, $response->getContent());
        $this->assertStringNotContainsString('images/'.$image->getKey(), $response->getContent());
    }

    public function test_a_block_that_breaks_is_left_out(): void
    {
        $broken = SiteContent::block('hero');
        $broken['fields']['heading'] = ['not', 'text'];

        $this->site->forceFill(['content' => $this->document([$broken, SiteContent::block('text', ['heading' => 'Still here'])])])->save();

        $this->get('/sites/brand')->assertOk()->assertSee('Still here')->assertDontSee('block--hero', false);
    }

    public function test_style_reaches_the_page(): void
    {
        $content = $this->document([SiteContent::block('cta')]);
        $content['style'] = ['primary' => '#123456', 'accent' => '#654321', 'fonts' => 'lora-nunito', 'buttons' => 'pill'];
        $this->site->forceFill(['content' => $content])->save();

        $this->get('/sites/brand')
            ->assertOk()
            ->assertSee('--brand:#123456', false)
            ->assertSee('--accent:#654321', false)
            ->assertSee('family=Lora', false)
            ->assertSee('buttons-pill', false);

        // The search and property pages wear the same look.
        $this->get('/sites/brand/search')->assertOk()->assertSee('--brand:#123456', false);
    }

    public function test_a_site_image_is_served(): void
    {
        Storage::fake('media');
        Storage::disk('media')->put('websites/garden.jpg', 'JPEGBYTES');
        $image = WebsiteImage::query()->create(['website_id' => $this->site->getKey(), 'path' => 'websites/garden.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 9]);

        $response = $this->get('/sites/brand/images/'.$image->getKey())->assertOk();
        $this->assertSame('JPEGBYTES', $response->streamedContent());

        $other = Website::query()->create(['slug' => 'other', 'name' => 'Other', 'kind' => Website::KIND_BRAND, 'status' => Website::STATUS_LIVE]);
        $this->get('/sites/other/images/'.$image->getKey())->assertNotFound();
        $this->assertNotNull($other);
    }

    // ------------------------------------------------------------------

    private function review(string $comment, int $rating, string $status = 'published'): void
    {
        $this->actingForOrganization($this->harbour);
        Review::query()->create([
            'organization_id' => $this->harbour->getKey(),
            'property_id' => $this->flat->property_id,
            'listing_id' => $this->flat->getKey(),
            'direction' => Review::GUEST_TO_HOST,
            'rating' => $rating,
            'rating_scale' => 5,
            'public_comment' => $comment,
            'status' => $status,
            'submitted_at' => now(),
        ]);
        $this->app->make(TenantContext::class)->clear();
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, mixed>
     */
    private function document(array $blocks, array $pages = []): array
    {
        return [
            'schema' => 1,
            'theme' => 'coastal',
            'style' => [],
            'pages' => [
                ['id' => 'pg_aaaaaaaaaaaa', 'kind' => 'home', 'title' => 'Home', 'slug' => '', 'in_menu' => true, 'blocks' => $blocks],
                ...$pages,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function page(string $title, string $slug, array $blocks = []): array
    {
        return ['id' => 'pg_'.substr(md5($slug), 0, 12), 'kind' => 'page', 'title' => $title, 'slug' => $slug, 'in_menu' => true, 'blocks' => $blocks];
    }
}
