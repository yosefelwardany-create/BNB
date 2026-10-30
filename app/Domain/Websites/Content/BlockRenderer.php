<?php

declare(strict_types=1);

namespace App\Domain\Websites\Content;

use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Reviews\Models\Review;
use App\Domain\Websites\Blocks\BlockRegistry;
use App\Domain\Websites\DataObjects\SearchCriteria;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteImage;
use App\Domain\Websites\Services\WebsiteCatalogue;
use App\Domain\Websites\Support\SiteFormat;
use App\Domain\Websites\Support\WebsiteMedia;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * One block of a page, as HTML, through its view.
 *
 * What a block points at is looked up here, for the site alone: its photos,
 * homes, reviews and the pages its buttons go to. Anything that has gone —
 * a deleted photo, a home taken off the site — is simply left out. A block
 * that fails to render at all is left out of the page and logged; a guest
 * never gets an error page because of one block.
 */
class BlockRenderer
{
    public function __construct(
        private readonly WebsiteCatalogue $catalogue,
        private readonly TenantContext $tenancy,
    ) {}

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $content  the document the block is in, for its links
     */
    public function render(Website $site, array $block, array $content, bool $preview = false): string
    {
        $type = BlockRegistry::get((string) ($block['type'] ?? ''));

        if ($type === null) {
            return '';
        }

        $fields = array_replace($type->defaults(), is_array($block['fields'] ?? null) ? $block['fields'] : []);
        $background = in_array($block['background'] ?? null, SiteContent::BACKGROUNDS, true) ? $block['background'] : 'plain';

        try {
            $html = view($type->view(), [
                'site' => $site,
                'f' => $fields,
                'content' => $content,
                'blocks' => $this,
                'catalogue' => $this->catalogue,
                'preview' => $preview,
                'editable' => self::editable($preview),
            ] + $this->data($site, $type->type, $fields))->render();
        } catch (Throwable $e) {
            Log::warning('A website block could not be rendered and was left out of the page.', [
                'website_id' => $site->getKey(),
                'block_id' => $block['id'] ?? null,
                'type' => $type->type,
                'exception_type' => $e::class,
            ]);

            return '';
        }

        // Nothing to show — a gallery without photos, reviews not yet
        // written — is no gap on a guest's page.
        if (! $preview && trim($html) === '') {
            return '';
        }

        return sprintf(
            '<section class="block block--%s bg--%s"%s>%s</section>',
            $type->type,
            $background,
            $preview ? ' data-block-id="'.e((string) ($block['id'] ?? '')).'"' : '',
            $html,
        );
    }

    /**
     * The attributes that let the editor's preview type a plain-text field in
     * place: its name, whether it takes several lines, and what to show
     * whenever it is empty — emptied by typing, too. Nothing at all on a
     * guest's page.
     *
     * @return \Closure(string, string, ?string=, bool=): HtmlString
     */
    private static function editable(bool $preview): \Closure
    {
        return static function (string $field, string $value, ?string $placeholder = null, bool $multiline = false) use ($preview): HtmlString {
            if (! $preview) {
                return new HtmlString('');
            }

            return new HtmlString(
                ' data-field="'.e($field).'"'
                .($multiline ? ' data-multiline' : '')
                .($placeholder !== null ? ' data-placeholder="'.e($placeholder).'"' : ''),
            );
        };
    }

    /**
     * Where a browser loads a block's photo from, or null when the photo is
     * not this site's to show (or no longer exists).
     */
    public function imageUrl(Website $site, mixed $ref): ?string
    {
        if (! is_string($ref) || ! str_contains($ref, ':')) {
            return null;
        }

        [$kind, $id] = explode(':', $ref, 2);

        if ($kind === 'img') {
            $image = WebsiteImage::query()->where('website_id', $site->getKey())->find($id);

            return $image === null ? null : self::imageAddress($site, $image);
        }

        if ($kind === 'photo') {
            $photo = $this->tenancy->withoutScope(fn (): ?PropertyPhoto => PropertyPhoto::query()->find($id));

            return $photo !== null && $this->catalogue->showsPhoto($site, $photo) ? $this->catalogue->photoUrl($site, $photo) : null;
        }

        return $kind === 'site' && in_array($id, WebsiteMedia::KINDS, true) ? WebsiteMedia::url($site, $id) : null;
    }

    public static function imageAddress(Website $site, WebsiteImage $image): string
    {
        if (config('filesystems.disks.'.WebsiteMedia::DISK.'.url') !== null) {
            return Storage::disk(WebsiteMedia::DISK)->url($image->path);
        }

        return $site->url('images/'.$image->getKey());
    }

    /**
     * Where a button goes, or null when it no longer goes anywhere.
     *
     * @param  array<string, mixed>  $content
     */
    public function href(Website $site, mixed $link, array $content): ?string
    {
        if (! is_array($link) || ! is_string($link['target'] ?? null)) {
            return null;
        }

        $target = $link['target'];

        return match ($link['kind'] ?? null) {
            'page' => ($page = SiteContent::pageById($content, $target)) === null ? null : SiteContent::pageUrl($site, $page),
            'search' => $site->url('search'),
            'email' => filter_var($target, FILTER_VALIDATE_EMAIL) !== false ? 'mailto:'.$target : null,
            'phone' => ($digits = preg_replace('/[^0-9+]/', '', $target)) !== '' ? 'tel:'.$digits : null,
            'url' => str_starts_with(strtolower($target), 'https://') ? RichText::safeHref($target) : null,
            default => null,
        };
    }

    /**
     * What a block's view needs beyond its own settings.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function data(Website $site, string $type, array $fields): array
    {
        return match ($type) {
            'hero' => ['image' => $this->imageUrl($site, $fields['image']), 'criteria' => new SearchCriteria],
            'homes' => ['homes' => $this->homes($site, $fields)],
            'text_image' => ['image' => $this->imageUrl($site, $fields['image'])],
            'gallery' => ['photos' => array_values(array_filter(array_map(
                fn (mixed $ref): ?string => $this->imageUrl($site, $ref),
                is_array($fields['images']) ? $fields['images'] : [],
            )))],
            'reviews' => ['reviews' => $this->reviews($site, $fields)],
            'map' => ['place' => $this->centre($site)],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return Collection<int, Listing>
     */
    private function homes(Website $site, array $fields): Collection
    {
        $limit = $fields['count'] === 'all' ? null : (int) $fields['count'];

        $homes = match ($fields['which']) {
            'all' => $this->catalogue->listings($site),
            'chosen' => (function () use ($site, $fields): Collection {
                $onSite = $this->catalogue->listings($site)->keyBy(fn (Listing $listing): string => $listing->getKey());

                return collect(is_array($fields['chosen']) ? $fields['chosen'] : [])
                    ->map(fn (mixed $id): ?Listing => is_string($id) ? $onSite->get($id) : null)
                    ->filter()
                    ->values();
            })(),
            default => $this->catalogue->featured($site),
        };

        return $limit === null ? $homes->values() : $homes->take($limit)->values();
    }

    /**
     * The best published reviews across the site's homes.
     *
     * @param  array<string, mixed>  $fields
     * @return Collection<int, Review>
     */
    private function reviews(Website $site, array $fields): Collection
    {
        $minimum = $fields['min_rating'] === 'any' ? 0 : (int) $fields['min_rating'];

        return $this->catalogue->listings($site)
            ->flatMap(fn (Listing $listing): Collection => $this->catalogue->reviews($listing, 12))
            ->unique(fn (Review $review): string => $review->getKey())
            ->filter(fn (Review $review): bool => $minimum === 0 || (int) $review->rating_percent >= $minimum)
            ->sortByDesc(fn (Review $review): string => sprintf('%03d|%s', (int) $review->rating_percent, $review->submitted_at?->toIso8601String() ?? ''))
            ->take((int) $fields['count'])
            ->values();
    }

    /**
     * The middle of the site's homes, as approximately as a property page
     * shows one. Null when none has a location.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    private function centre(Website $site): ?array
    {
        $points = $this->catalogue->listings($site)
            ->map(fn (Listing $listing): ?array => SiteFormat::approximately($listing->property?->latitude, $listing->property?->longitude))
            ->filter();

        if ($points->isEmpty()) {
            return null;
        }

        return SiteFormat::approximately($points->avg('latitude'), $points->avg('longitude'));
    }
}
