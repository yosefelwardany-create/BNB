<?php

declare(strict_types=1);

namespace Tests\Feature\Websites;

use App\Domain\Websites\Blocks\BlockRegistry;
use App\Domain\Websites\Content\ContentValidator;
use App\Domain\Websites\Content\SiteContent;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * A site's pages and blocks are one document, checked whole on every save:
 * nothing reaches a page that its block does not define.
 */
class SiteContentValidationTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Website::query()->create([
            'slug' => 'brand',
            'name' => 'Stay With Us',
            'kind' => Website::KIND_BRAND,
            'status' => Website::STATUS_DRAFT,
            'settings' => ['heading' => 'Sea & sun', 'tagline' => 'Book direct', 'primary_colour' => '#112233'],
        ]);
    }

    public function test_every_block_is_defined_once(): void
    {
        $this->assertSame(
            ['hero', 'homes', 'text', 'text_image', 'gallery', 'reviews', 'highlights', 'map', 'faq', 'cta', 'contact', 'spacer'],
            array_keys(BlockRegistry::all()),
        );
        $this->assertSame('Big photo + search', BlockRegistry::get('hero')?->label);
        $this->assertNull(BlockRegistry::get('marquee'));
    }

    public function test_a_starter_document_from_stage_one_settings_is_valid(): void
    {
        $content = $this->validate(SiteContent::fromSettings($this->site));

        $this->assertSame('coastal', $content['theme']);
        $this->assertSame('#112233', $content['style']['primary']);
        $this->assertSame('home', $content['pages'][0]['kind']);
        $this->assertSame(['hero', 'homes'], array_column($content['pages'][0]['blocks'], 'type'));
        $this->assertSame('Sea & sun', $content['pages'][0]['blocks'][0]['fields']['heading']);
        $this->assertSame('Book direct', $content['pages'][0]['blocks'][0]['fields']['tagline']);
    }

    public function test_a_new_site_starts_laid_out(): void
    {
        $content = $this->validate(SiteContent::starter($this->site));

        $this->assertSame(['hero', 'homes', 'text', 'contact'], array_column($content['pages'][0]['blocks'], 'type'));
    }

    public function test_an_unknown_block_type_is_refused_by_its_path(): void
    {
        $content = $this->document([['id' => 'bl_aaaaaaaaaaaa', 'type' => 'marquee', 'fields' => []]]);

        $this->assertRefusedAt('pages.0.blocks.0.type', $content);
    }

    public function test_a_too_long_heading_is_refused_by_its_path(): void
    {
        $content = $this->document([$this->block('hero', ['heading' => str_repeat('a', 121)])]);

        $this->assertRefusedAt('pages.0.blocks.0.fields.heading', $content);
    }

    public function test_unknown_fields_are_dropped_and_missing_ones_take_their_default(): void
    {
        $content = $this->validate($this->document([$this->block('hero', ['heading' => 'Hi', 'colour' => 'red'])]));
        $fields = $content['pages'][0]['blocks'][0]['fields'];

        $this->assertArrayNotHasKey('colour', $fields);
        $this->assertSame('Hi', $fields['heading']);
        $this->assertSame('light', $fields['text_tone']);
        $this->assertTrue($fields['show_search']);
        $this->assertSame('plain', $content['pages'][0]['blocks'][0]['background']);
    }

    public function test_choices_and_toggles_are_held_to_their_kind(): void
    {
        $this->assertRefusedAt('pages.0.blocks.0.fields.text_tone', $this->document([$this->block('hero', ['text_tone' => 'purple'])]));
        $this->assertRefusedAt('pages.0.blocks.0.fields.show_search', $this->document([$this->block('hero', ['show_search' => 'yes'])]));
        $this->assertRefusedAt('pages.0.blocks.0.background', $this->document([['background' => 'stripes'] + $this->block('hero')]));
    }

    public function test_rich_text_is_cleaned_on_save(): void
    {
        $content = $this->validate($this->document([$this->block('text', ['body' => '<p>Hello<script>alert(1)</script></p>'])]));

        $this->assertSame('<p>Hello</p>', $content['pages'][0]['blocks'][0]['fields']['body']);
    }

    public function test_items_are_checked_one_by_one(): void
    {
        $faq = $this->block('faq', ['items' => [
            ['question' => 'Pets?', 'answer' => 'Yes, small ones.'],
            ['question' => str_repeat('q', 161), 'answer' => 'No.'],
        ]]);
        $this->assertRefusedAt('pages.0.blocks.0.fields.items.1.question', $this->document([$faq]));

        $many = $this->block('highlights', ['items' => array_fill(0, 7, ['icon' => 'wifi', 'text' => 'Fast wifi'])]);
        $this->assertRefusedAt('pages.0.blocks.0.fields.items', $this->document([$many]));

        $icon = $this->block('highlights', ['items' => [['icon' => 'rocket', 'text' => 'Fast']]]);
        $this->assertRefusedAt('pages.0.blocks.0.fields.items.0.icon', $this->document([$icon]));
    }

    public function test_a_page_slug_may_not_be_reserved_or_repeated(): void
    {
        $this->assertRefusedAt('pages.1.slug', $this->document([], [$this->page('Search', 'search')]));
        $this->assertRefusedAt('pages.2.slug', $this->document([], [$this->page('About', 'about'), $this->page('About again', 'about')]));
        $this->assertRefusedAt('pages.1.slug', $this->document([], [$this->page('Shouty', 'About Us')]));
    }

    public function test_the_home_page_is_first_and_alone(): void
    {
        $noHome = ['schema' => 1, 'theme' => 'coastal', 'style' => [], 'pages' => [$this->page('About', 'about')]];
        $this->assertRefusedAt('pages', $noHome);

        $twoHomes = $this->document([]);
        $twoHomes['pages'][] = $twoHomes['pages'][0];
        $twoHomes['pages'][1]['id'] = 'pg_bbbbbbbbbbbb';
        $this->assertRefusedAt('pages', $twoHomes);
    }

    public function test_limits_on_pages_and_blocks(): void
    {
        $pages = [];
        for ($i = 1; $i <= 20; $i++) {
            $pages[] = $this->page('Page '.$i, 'page-'.$i);
        }
        $this->assertRefusedAt('pages', $this->document([], $pages));

        $this->assertRefusedAt('pages.0.blocks', $this->document(array_map(fn (): array => $this->block('spacer'), range(1, 31))));

        // Twenty pages, Home among them, is the most there may be.
        array_pop($pages);
        $this->assertCount(20, $this->validate($this->document([], $pages))['pages']);
    }

    public function test_block_and_page_ids_are_unique(): void
    {
        $block = $this->block('spacer');
        $this->assertRefusedAt('pages.0.blocks.1.id', $this->document([$block, $block]));
    }

    public function test_an_image_must_belong_to_the_site(): void
    {
        $other = Website::query()->create(['slug' => 'other', 'name' => 'Other', 'kind' => Website::KIND_BRAND, 'status' => Website::STATUS_DRAFT]);
        $theirs = WebsiteImage::query()->create(['website_id' => $other->getKey(), 'path' => 'x.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10]);
        $ours = WebsiteImage::query()->create(['website_id' => $this->site->getKey(), 'path' => 'y.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10]);

        $this->assertRefusedAt('pages.0.blocks.0.fields.image', $this->document([$this->block('hero', ['image' => 'img:'.$theirs->getKey()])]));
        $this->assertRefusedAt('pages.0.blocks.0.fields.image', $this->document([$this->block('hero', ['image' => 'https://evil.test/x.jpg'])]));

        $content = $this->validate($this->document([$this->block('hero', ['image' => 'img:'.$ours->getKey()])]));
        $this->assertSame('img:'.$ours->getKey(), $content['pages'][0]['blocks'][0]['fields']['image']);

        $gallery = $this->block('gallery', ['images' => array_fill(0, 13, 'img:'.$ours->getKey())]);
        $this->assertRefusedAt('pages.0.blocks.0.fields.images', $this->document([$gallery]));
    }

    public function test_a_link_must_be_safe(): void
    {
        $about = $this->page('About', 'about');

        $bad = $this->block('cta', ['text' => 'Hi', 'button' => ['label' => 'Go', 'kind' => 'url', 'target' => 'javascript:alert(1)']]);
        $this->assertRefusedAt('pages.0.blocks.0.fields.button.target', $this->document([$bad]));

        $missing = $this->block('cta', ['text' => 'Hi', 'button' => ['label' => 'Go', 'kind' => 'page', 'target' => 'pg_nothere00000']]);
        $this->assertRefusedAt('pages.0.blocks.0.fields.button.target', $this->document([$missing], [$about]));

        $good = $this->block('cta', ['text' => 'Hi', 'button' => ['label' => 'Read', 'kind' => 'page', 'target' => $about['id']]]);
        $content = $this->validate($this->document([$good], [$about]));
        $this->assertSame($about['id'], $content['pages'][0]['blocks'][0]['fields']['button']['target']);

        $email = $this->block('cta', ['text' => 'Hi', 'button' => ['label' => 'Write', 'kind' => 'email', 'target' => 'not-an-email']]);
        $this->assertRefusedAt('pages.0.blocks.0.fields.button.target', $this->document([$email]));
    }

    public function test_style_values_are_checked(): void
    {
        $this->assertRefusedAt('theme', ['theme' => 'neon'] + $this->document([]));
        $this->assertRefusedAt('style.fonts', $this->withStyle(['fonts' => 'comic-sans']));
        $this->assertRefusedAt('style.buttons', $this->withStyle(['buttons' => 'blob']));
        $this->assertRefusedAt('style.primary', $this->withStyle(['primary' => 'red']));

        $content = $this->validate($this->withStyle(['primary' => '#ABCDEF', 'fonts' => 'lora-nunito', 'buttons' => 'pill']));
        $this->assertSame(['primary' => '#abcdef', 'accent' => '#e07a5f', 'fonts' => 'lora-nunito', 'buttons' => 'pill'], $content['style']);
    }

    // ------------------------------------------------------------------

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
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    private function withStyle(array $style): array
    {
        return array_replace($this->document([]), ['style' => $style]);
    }

    /**
     * @return array<string, mixed>
     */
    private function page(string $title, string $slug): array
    {
        return ['id' => 'pg_'.substr(md5($title.$slug), 0, 12), 'kind' => 'page', 'title' => $title, 'slug' => $slug, 'in_menu' => true, 'blocks' => []];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function block(string $type, array $fields = []): array
    {
        static $n = 0;
        $n++;

        return ['id' => 'bl_'.str_pad((string) $n, 12, '0', STR_PAD_LEFT), 'type' => $type, 'background' => 'plain', 'fields' => $fields];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function validate(array $content): array
    {
        return $this->app->make(ContentValidator::class)->validate($this->site, $content);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function assertRefusedAt(string $path, array $content): void
    {
        try {
            $this->validate($content);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($path, $e->errors(), 'Refused, but at '.implode(', ', array_keys($e->errors())));

            return;
        }

        $this->fail('The document was accepted; expected a refusal at '.$path);
    }
}
