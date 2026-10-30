<?php

declare(strict_types=1);

namespace App\Domain\Websites\Content;

use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Websites\Blocks\BlockField;
use App\Domain\Websites\Blocks\BlockRegistry;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteImage;
use App\Domain\Websites\Services\WebsiteCatalogue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * A site's document, checked whole before it is saved, and returned clean.
 *
 * Every block is held to its definition: known types and fields, the kind
 * and length of each value, the choices it may take, the photos and pages it
 * may point at. Unknown fields are dropped, missing ones take their default,
 * and rich text is cleaned. A refusal names the path of what is wrong
 * (`pages.0.blocks.2.fields.heading`), so the editor can point at it.
 */
class ContentValidator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @var list<string> */
    private array $pageIds = [];

    /** @var array<string, true> */
    private array $siteListings = [];

    private Website $site;

    public function __construct(
        private readonly WebsiteCatalogue $catalogue,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(Website $site, array $content): array
    {
        $this->errors = [];
        $this->site = $site;
        $this->siteListings = array_fill_keys($site->listings()->pluck('listing_id')->all(), true);

        $theme = $content['theme'] ?? SiteStyle::DEFAULT_THEME;

        if (! is_string($theme) || ! isset(SiteStyle::THEMES[$theme])) {
            $this->fail('theme', 'Choose one of the themes.');
        }

        $clean = [
            'schema' => SiteContent::SCHEMA,
            'theme' => is_string($theme) ? $theme : SiteStyle::DEFAULT_THEME,
            'style' => $this->style(is_array($content['style'] ?? null) ? $content['style'] : []),
            'pages' => $this->pages($content['pages'] ?? null),
        ];

        if ($this->errors !== []) {
            throw ValidationException::withMessages($this->errors);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $style
     * @return array<string, string>
     */
    private function style(array $style): array
    {
        $clean = SiteStyle::defaults();

        foreach (['primary', 'accent'] as $key) {
            if (! array_key_exists($key, $style)) {
                continue;
            }

            SiteStyle::isColour($style[$key])
                ? $clean[$key] = strtolower($style[$key])
                : $this->fail('style.'.$key, 'A colour is written like #1f4e5f.');
        }

        if (array_key_exists('fonts', $style)) {
            is_string($style['fonts']) && isset(SiteStyle::FONT_PAIRS[$style['fonts']])
                ? $clean['fonts'] = $style['fonts']
                : $this->fail('style.fonts', 'Choose one of the font pairings.');
        }

        if (array_key_exists('buttons', $style)) {
            is_string($style['buttons']) && isset(SiteStyle::BUTTONS[$style['buttons']])
                ? $clean['buttons'] = $style['buttons']
                : $this->fail('style.buttons', 'Choose one of the button shapes.');
        }

        return $clean;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pages(mixed $pages): array
    {
        if (! is_array($pages) || ! array_is_list($pages) || $pages === []) {
            $this->fail('pages', 'A site needs its home page.');

            return [];
        }

        if (count($pages) > SiteContent::MAX_PAGES) {
            $this->fail('pages', sprintf('A site can have up to %d pages.', SiteContent::MAX_PAGES));
        }

        $homes = array_keys(array_filter($pages, fn (mixed $page): bool => is_array($page) && ($page['kind'] ?? null) === 'home'));

        if ($homes !== [0]) {
            $this->fail('pages', 'The first page is Home, and there is only one.');
        }

        // Every page's id first, so a button may link to a page after it.
        $this->pageIds = [];

        foreach ($pages as $i => $page) {
            $id = is_array($page) ? ($page['id'] ?? null) : null;

            if (! is_string($id) || preg_match('/^pg_[a-z0-9]{12}$/', $id) !== 1 || in_array($id, $this->pageIds, true)) {
                $this->fail("pages.$i.id", 'Each page needs its own id.');
            } else {
                $this->pageIds[] = $id;
            }
        }

        $clean = [];
        $slugs = [];
        $blockIds = [];

        foreach ($pages as $i => $page) {
            if (! is_array($page)) {
                $this->fail("pages.$i", 'This page could not be read.');

                continue;
            }

            $home = ($page['kind'] ?? null) === 'home';
            $title = $page['title'] ?? '';

            if (! is_string($title) || trim($title) === '' || mb_strlen($title) > 60) {
                $this->fail("pages.$i.title", 'A page needs a name of up to 60 characters.');
            }

            $slug = $home ? '' : ($page['slug'] ?? '');

            if (! $home) {
                if (! is_string($slug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || mb_strlen($slug) > 60) {
                    $this->fail("pages.$i.slug", 'An address is lowercase letters and numbers, joined by hyphens.');
                } elseif (in_array($slug, SiteContent::RESERVED_SLUGS, true)) {
                    $this->fail("pages.$i.slug", sprintf('"%s" is already a page of every site. Choose another address.', $slug));
                } elseif (in_array($slug, $slugs, true)) {
                    $this->fail("pages.$i.slug", 'Another page already has this address.');
                } else {
                    $slugs[] = $slug;
                }
            }

            $clean[] = [
                'id' => is_string($page['id'] ?? null) ? $page['id'] : '',
                'kind' => $home ? 'home' : 'page',
                'title' => is_string($title) ? trim($title) : '',
                'slug' => is_string($slug) ? $slug : '',
                'in_menu' => ! array_key_exists('in_menu', $page) || $page['in_menu'] === true,
                'blocks' => $this->blocks($page['blocks'] ?? [], "pages.$i.blocks", $blockIds),
            ];
        }

        return $clean;
    }

    /**
     * @param  list<string>  $blockIds
     * @return list<array<string, mixed>>
     */
    private function blocks(mixed $blocks, string $path, array &$blockIds): array
    {
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            $this->fail($path, 'The blocks of this page could not be read.');

            return [];
        }

        if (count($blocks) > SiteContent::MAX_BLOCKS) {
            $this->fail($path, sprintf('A page can hold up to %d blocks.', SiteContent::MAX_BLOCKS));
        }

        $clean = [];

        foreach ($blocks as $j => $block) {
            $at = "$path.$j";

            if (! is_array($block)) {
                $this->fail($at, 'This block could not be read.');

                continue;
            }

            $id = $block['id'] ?? null;

            if (! is_string($id) || preg_match('/^bl_[a-z0-9]{12}$/', $id) !== 1 || in_array($id, $blockIds, true)) {
                $this->fail("$at.id", 'Each block needs its own id.');
            } else {
                $blockIds[] = $id;
            }

            $definition = is_string($block['type'] ?? null) ? BlockRegistry::get($block['type']) : null;

            if ($definition === null) {
                $this->fail("$at.type", 'This kind of block does not exist.');

                continue;
            }

            $background = $block['background'] ?? 'plain';

            if (! in_array($background, SiteContent::BACKGROUNDS, true)) {
                $this->fail("$at.background", 'Choose plain, tint or the theme colour.');
            }

            $fields = is_array($block['fields'] ?? null) ? $block['fields'] : [];
            $cleanFields = [];

            foreach ($definition->fields as $field) {
                $cleanFields[$field->name] = array_key_exists($field->name, $fields)
                    ? $this->value($field, $fields[$field->name], "$at.fields.{$field->name}")
                    : $field->default;
            }

            $clean[] = [
                'id' => is_string($id) ? $id : '',
                'type' => $definition->type,
                'background' => is_string($background) ? $background : 'plain',
                'fields' => $cleanFields,
            ];
        }

        return $clean;
    }

    private function value(BlockField $field, mixed $value, string $path): mixed
    {
        return match ($field->kind) {
            'text' => $this->text($field, $value, $path),
            'rich_text' => is_string($value) || $value === null
                ? RichText::clean((string) $value, $field->max)
                : $this->fail($path, 'This text could not be read.', ''),
            'image' => $this->image($value, $path),
            'images' => $this->images($field, $value, $path),
            'choice' => $this->choice($field, $value, $path),
            'toggle' => is_bool($value) ? $value : $this->fail($path, 'This is either on or off.', $field->default),
            'items' => $this->items($field, $value, $path),
            'link' => $this->link($value, $path),
            'listings' => $this->listings($value, $path),
            default => $field->default,
        };
    }

    private function text(BlockField $field, mixed $value, string $path): string
    {
        if ($value === null) {
            return '';
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return $this->fail($path, 'This text could not be read.', '');
        }

        $value = (string) $value;

        if ($field->max !== null && mb_strlen($value) > $field->max) {
            $this->fail($path, sprintf('Up to %d characters.', $field->max));
        }

        return $value;
    }

    private function choice(BlockField $field, mixed $value, string $path): string
    {
        $value = is_int($value) ? (string) $value : $value;

        return is_string($value) && in_array($value, $field->optionValues(), true)
            ? $value
            : $this->fail($path, 'Choose one of the options.', (string) $field->default);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(BlockField $field, mixed $value, string $path): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return $this->fail($path, 'This list could not be read.', []);
        }

        if (count($value) > $field->maxItems) {
            $this->fail($path, sprintf('Up to %d.', $field->maxItems));
        }

        $clean = [];

        foreach (array_slice($value, 0, $field->maxItems) as $k => $item) {
            $item = is_array($item) ? $item : [];
            $row = [];

            foreach ($field->itemFields as $itemField) {
                $row[$itemField->name] = array_key_exists($itemField->name, $item)
                    ? $this->value($itemField, $item[$itemField->name], "$path.$k.{$itemField->name}")
                    : $itemField->default;
            }

            $clean[] = $row;
        }

        return $clean;
    }

    private function image(mixed $value, string $path): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) && $this->imageBelongs($value)
            ? $value
            : $this->fail($path, 'Choose a photo from this site.', null);
    }

    /**
     * @return list<string>
     */
    private function images(BlockField $field, mixed $value, string $path): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return $this->fail($path, 'These photos could not be read.', []);
        }

        if (count($value) > $field->maxItems) {
            $this->fail($path, sprintf('Up to %d photos.', $field->maxItems));
        }

        $clean = [];

        foreach ($value as $k => $ref) {
            if (is_string($ref) && $this->imageBelongs($ref)) {
                $clean[] = $ref;
            } else {
                $this->fail("$path.$k", 'Choose a photo from this site.');
            }
        }

        return $clean;
    }

    /**
     * A photo this site may show: its own upload, a photo of one of its
     * homes, or its stage 1 logo or hero.
     */
    private function imageBelongs(string $ref): bool
    {
        [$kind, $id] = array_pad(explode(':', $ref, 2), 2, '');

        return match ($kind) {
            'img' => WebsiteImage::query()->where('website_id', $this->site->getKey())->whereKey($id)->exists(),
            'photo' => $this->photoBelongs($id),
            'site' => in_array($id, ['hero', 'logo'], true) && is_string($this->site->setting($id.'_path')),
            default => false,
        };
    }

    private function photoBelongs(string $id): bool
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id) !== 1) {
            return false;
        }

        $photo = $this->tenancy->withoutScope(fn (): ?PropertyPhoto => PropertyPhoto::query()->find($id));

        return $photo !== null && $this->catalogue->showsPhoto($this->site, $photo);
    }

    /**
     * @return array{label: string, kind: string, target: string}|null
     */
    private function link(mixed $value, string $path): ?array
    {
        if ($value === null || ! is_array($value)) {
            return $value === null ? null : $this->fail($path, 'This button could not be read.', null);
        }

        $label = $value['label'] ?? '';
        $kind = $value['kind'] ?? 'page';
        $target = $value['target'] ?? '';

        if (! is_string($label) || mb_strlen($label) > 40) {
            $this->fail("$path.label", 'A button label is up to 40 characters.');
        }

        if (! is_string($target)) {
            $target = '';
        }

        $target = trim($target);

        $ok = match ($kind) {
            'page' => in_array($target, $this->pageIds, true),
            'search' => true,
            'email' => filter_var($target, FILTER_VALIDATE_EMAIL) !== false,
            'phone' => preg_match('/^[0-9+()\s.\-]{4,30}$/', $target) === 1,
            'url' => str_starts_with(strtolower($target), 'https://') && RichText::safeHref($target) !== null,
            default => null,
        };

        if ($ok === null) {
            $this->fail("$path.kind", 'Choose where the button goes.');
        } elseif (! $ok && is_string($label) && trim($label) !== '') {
            $this->fail("$path.target", match ($kind) {
                'page' => 'Choose one of the site\'s pages.',
                'email' => 'Enter an email address.',
                'phone' => 'Enter a phone number.',
                default => 'Enter a web address starting with https://.',
            });
        }

        // A button without a label is no button.
        if (! is_string($label) || trim($label) === '') {
            return null;
        }

        return ['label' => trim($label), 'kind' => (string) $kind, 'target' => $kind === 'search' ? '' : $target];
    }

    /**
     * The chosen homes, kept only while they are on the site.
     *
     * @return list<string>
     */
    private function listings(mixed $value, string $path): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return $this->fail($path, 'These homes could not be read.', []);
        }

        return array_values(array_unique(array_filter($value, fn (mixed $id): bool => is_string($id) && isset($this->siteListings[$id]))));
    }

    /**
     * @template T
     *
     * @param  T  $fallback
     * @return T
     */
    private function fail(string $path, string $message, mixed $fallback = null): mixed
    {
        $this->errors[$path] ??= $message;

        return $fallback;
    }
}
