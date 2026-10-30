<?php

declare(strict_types=1);

namespace App\Domain\Websites\Content;

use App\Domain\Websites\Blocks\BlockRegistry;
use App\Domain\Websites\Models\Website;
use Illuminate\Support\Str;

/**
 * A site's pages, blocks, theme and style, as one document.
 *
 * Kept twice on the site: `draft_content`, which editors see and save into,
 * and `content`, which guests see and Publish copies the draft to. A site
 * made before the editor has neither, and is read as the page it had:
 * {@see fromSettings()}.
 *
 *     { schema, theme, style: {primary, accent, fonts, buttons},
 *       pages: [ {id, kind: home|page, title, slug, in_menu,
 *                 blocks: [ {id, type, background, fields} ]} ] }
 */
final class SiteContent
{
    public const SCHEMA = 1;

    public const MAX_PAGES = 20;

    public const MAX_BLOCKS = 30;

    /** Addresses a page may not take: the site's own pages and files. */
    public const RESERVED_SLUGS = [
        'search', 'stay', 'checkout', 'booking', 'api', 'photos', 'media', 'images', 'preview', 'sitemap.xml', 'robots.txt',
    ];

    public const BACKGROUNDS = ['plain', 'tint', 'brand'];

    /**
     * What guests see.
     *
     * @return array<string, mixed>
     */
    public static function published(Website $site): array
    {
        return is_array($site->content) && $site->content !== [] ? $site->content : self::fromSettings($site);
    }

    /**
     * What editors see: the draft, or what is live when nothing has been
     * changed since.
     *
     * @return array<string, mixed>
     */
    public static function draft(Website $site): array
    {
        return is_array($site->draft_content) && $site->draft_content !== [] ? $site->draft_content : self::published($site);
    }

    /**
     * The page a site made before the editor already had: its big photo with
     * heading and tagline, then its featured homes, in its two colours.
     *
     * @return array<string, mixed>
     */
    public static function fromSettings(Website $site): array
    {
        // The same ids every time it is read, so this page and the editor's
        // first draft of it are recognisably the same page.
        $id = fn (string $prefix, string $what): string => $prefix.substr(sha1($site->getKey().'|'.$what), 0, 12);

        return self::document($site, [
            self::block('hero', array_filter([
                'heading' => $site->setting('heading') ?: $site->name,
                'tagline' => (string) $site->setting('tagline'),
                'image' => $site->setting('hero_path') ? 'site:hero' : null,
            ], fn (mixed $value): bool => $value !== null), id: $id('bl_', 'hero')),
            self::block('homes', id: $id('bl_', 'homes')),
        ], $id('pg_', 'home'));
    }

    /**
     * A new site's home page, laid out so nobody starts from a blank page.
     *
     * @return array<string, mixed>
     */
    public static function starter(Website $site): array
    {
        return self::document($site, [
            self::block('hero', array_filter([
                'heading' => $site->setting('heading') ?: null,
                'tagline' => $site->setting('tagline') ?: null,
            ])),
            self::block('homes'),
            self::block('text', [
                'heading' => 'About us',
                'body' => '<p>Tell guests who you are and what makes your homes special.</p>',
            ]),
            self::block('contact'),
        ]);
    }

    /**
     * A new block of a type, at its defaults with any fields given.
     *
     * @param  array<string, mixed>  $fields
     * @return array{id: string, type: string, background: string, fields: array<string, mixed>}
     */
    public static function block(string $type, array $fields = [], string $background = 'plain', ?string $id = null): array
    {
        $definition = BlockRegistry::get($type) ?? throw new \InvalidArgumentException('Unknown block type '.$type);

        return [
            'id' => $id ?? self::newId('bl_'),
            'type' => $type,
            'background' => $background,
            'fields' => array_replace($definition->defaults(), $fields),
        ];
    }

    public static function newId(string $prefix): string
    {
        return $prefix.Str::lower(Str::random(12));
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function homePage(array $content): array
    {
        foreach ($content['pages'] ?? [] as $page) {
            if (($page['kind'] ?? null) === 'home') {
                return $page;
            }
        }

        return ['id' => '', 'kind' => 'home', 'title' => 'Home', 'slug' => '', 'in_menu' => true, 'blocks' => []];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>|null
     */
    public static function pageBySlug(array $content, string $slug): ?array
    {
        foreach ($content['pages'] ?? [] as $page) {
            if (($page['kind'] ?? null) === 'page' && ($page['slug'] ?? null) === $slug) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>|null
     */
    public static function pageById(array $content, string $id): ?array
    {
        foreach ($content['pages'] ?? [] as $page) {
            if (($page['id'] ?? null) === $id) {
                return $page;
            }
        }

        return null;
    }

    /**
     * A page's address on the site.
     *
     * @param  array<string, mixed>  $page
     */
    public static function pageUrl(Website $site, array $page): string
    {
        return ($page['kind'] ?? null) === 'home' ? $site->url() : $site->url((string) ($page['slug'] ?? ''));
    }

    /**
     * @param  list<array<string, mixed>>  $homeBlocks
     * @return array<string, mixed>
     */
    private static function document(Website $site, array $homeBlocks, ?string $homeId = null): array
    {
        $colour = fn (string $key): ?string => SiteStyle::isColour($site->setting($key)) ? strtolower((string) $site->setting($key)) : null;

        return [
            'schema' => self::SCHEMA,
            'theme' => SiteStyle::DEFAULT_THEME,
            'style' => array_replace(SiteStyle::defaults(), array_filter([
                'primary' => $colour('primary_colour'),
                'accent' => $colour('accent_colour'),
            ])),
            'pages' => [[
                'id' => $homeId ?? self::newId('pg_'),
                'kind' => 'home',
                'title' => 'Home',
                'slug' => '',
                'in_menu' => true,
                'blocks' => $homeBlocks,
            ]],
        ];
    }
}
