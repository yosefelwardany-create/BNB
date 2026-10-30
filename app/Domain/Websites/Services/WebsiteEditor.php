<?php

declare(strict_types=1);

namespace App\Domain\Websites\Services;

use App\Domain\Listings\Models\Listing;
use App\Domain\Websites\Blocks\BlockRegistry;
use App\Domain\Websites\Content\BlockRenderer;
use App\Domain\Websites\Content\ContentValidator;
use App\Domain\Websites\Content\SiteContent;
use App\Domain\Websites\Content\SiteStyle;
use App\Domain\Websites\Exceptions\WebsiteDraftConflict;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteImage;
use App\Domain\Websites\Support\WebsiteMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * What the page builder does to a site: open its draft, save it without
 * overwriting anyone, put it live, throw it away, and keep the site's own
 * photos.
 *
 * Shared by the platform's editor and, in the client portal, a client's
 * editor of their own site; who may call it is the controllers' business.
 */
class WebsiteEditor
{
    /** How long a preview address works before the editor asks for another. */
    private const PREVIEW_HOURS = 2;

    /** At most this many photos of each home are offered to the photo picker. */
    private const PHOTOS_PER_HOME = 8;

    public function __construct(
        private readonly ContentValidator $validator,
        private readonly WebsiteCatalogue $catalogue,
        private readonly BlockRenderer $blocks,
    ) {}

    /**
     * Everything the editor opens with.
     *
     * @return array<string, mixed>
     */
    public function state(Website $site, ?string $pageId = null): array
    {
        return [
            'website' => [
                'id' => $site->getKey(),
                'name' => $site->name,
                'slug' => $site->slug,
                'kind' => $site->kind,
                'status' => $site->status,
                'public_url' => $site->url(),
            ],
            'content' => SiteContent::draft($site),
            'version' => (int) $site->draft_version,
            'published_at' => $site->content_published_at?->toIso8601String(),
            'unpublished_changes' => $this->unpublished($site),
            'preview_url' => $this->previewUrl($site, $pageId),
            'definitions' => BlockRegistry::toArray(),
            'themes' => self::options(SiteStyle::THEMES),
            'font_pairs' => self::options(array_map(fn (array $pair): string => $pair[0], SiteStyle::FONT_PAIRS)),
            'buttons' => self::options(SiteStyle::BUTTONS),
            'images' => $site->images()->get()->map(fn (WebsiteImage $image): array => self::image($site, $image))->values()->all(),
            'photos' => $this->photos($site),
            // The homes the site shows, for the blocks that pick some.
            'homes' => $this->catalogue->listings($site)
                ->map(fn (Listing $listing): array => ['listing_id' => $listing->getKey(), 'title' => $listing->displayTitle()])
                ->values()
                ->all(),
        ];
    }

    /**
     * Save the draft, if nobody else has since the editor last read it. The
     * draft comes back as it was kept, pasted markup cut down and all.
     *
     * @param  array<string, mixed>  $content
     * @return array{version: int, unpublished_changes: bool, content: array<string, mixed>}
     *
     * @throws WebsiteDraftConflict
     * @throws ValidationException
     */
    public function saveDraft(Website $site, array $content, int $version): array
    {
        return DB::transaction(function () use ($site, $content, $version): array {
            $locked = Website::query()->lockForUpdate()->findOrFail($site->getKey());

            if ((int) $locked->draft_version !== $version) {
                throw new WebsiteDraftConflict;
            }

            $locked->forceFill([
                'draft_content' => $this->validator->validate($locked, $content),
                'draft_version' => $version + 1,
            ])->save();

            return [
                'version' => (int) $locked->draft_version,
                'unpublished_changes' => $this->unpublished($locked),
                'content' => $locked->draft_content,
            ];
        });
    }

    /**
     * One block as the preview shows it, from the document as it is on the
     * editor's screen — checked as a save would check it, and saved nowhere.
     * The editor swaps it into the page in place of a reload.
     *
     * @param  array<string, mixed>  $content
     *
     * @throws ValidationException
     */
    public function renderBlock(Website $site, array $content, string $blockId): string
    {
        $clean = $this->validator->validate($site, $content);

        foreach ($clean['pages'] as $page) {
            foreach ($page['blocks'] as $block) {
                if ($block['id'] === $blockId) {
                    return $this->blocks->render($site, $block, $clean, preview: true);
                }
            }
        }

        throw ValidationException::withMessages(['block' => 'This block is not on the site.']);
    }

    /**
     * Put the draft live, checked once more against what may be on a page
     * now — a photo or a home may have gone since it was saved. Only the
     * draft the editor is looking at: if someone has saved since, what it
     * would put live is not what is on its screen.
     *
     * @throws WebsiteDraftConflict
     * @throws ValidationException
     */
    public function publish(Website $site, int $version): Website
    {
        return DB::transaction(function () use ($site, $version): Website {
            $locked = Website::query()->lockForUpdate()->findOrFail($site->getKey());

            if ((int) $locked->draft_version !== $version) {
                throw new WebsiteDraftConflict;
            }

            $locked->forceFill([
                'content' => $this->validator->validate($locked, SiteContent::draft($locked)),
                'content_published_at' => now(),
            ])->save();

            return $locked;
        });
    }

    /**
     * Throw the draft away: it becomes what is live again.
     */
    public function discard(Website $site): Website
    {
        return DB::transaction(function () use ($site): Website {
            $locked = Website::query()->lockForUpdate()->findOrFail($site->getKey());

            $locked->forceFill([
                'draft_content' => SiteContent::published($locked),
                'draft_version' => (int) $locked->draft_version + 1,
            ])->save();

            return $locked;
        });
    }

    /**
     * The address of the site's draft, page by page, for the editor's
     * preview. Signed and short-lived: it shows a draft nobody else should.
     */
    public function previewUrl(Website $site, ?string $pageId = null): string
    {
        return URL::temporarySignedRoute(
            'sites.preview',
            now()->addHours(self::PREVIEW_HOURS),
            array_filter(['site' => $site->slug, 'page' => $pageId]),
        );
    }

    public function storeImage(Website $site, UploadedFile $file, ?string $alt = null): WebsiteImage
    {
        $path = $file->store('websites/'.$site->getKey().'/images', WebsiteMedia::DISK);

        return WebsiteImage::query()->create([
            'website_id' => $site->getKey(),
            'path' => $path,
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'alt' => $alt,
        ]);
    }

    /**
     * Remove a photo the site no longer uses anywhere — not in the draft,
     * not in what is live.
     *
     * @throws ValidationException
     */
    public function deleteImage(Website $site, WebsiteImage $image): void
    {
        $ref = json_encode($image->ref());

        foreach ([SiteContent::draft($site), SiteContent::published($site)] as $content) {
            if (str_contains((string) json_encode($content), (string) $ref)) {
                throw ValidationException::withMessages(['image' => 'This photo is used on the site. Take it out of its blocks first.']);
            }
        }

        Storage::disk(WebsiteMedia::DISK)->delete($image->path);
        $image->delete();
    }

    /**
     * @return array{id: string, ref: string, url: string, alt: string|null}
     */
    public static function image(Website $site, WebsiteImage $image): array
    {
        return [
            'id' => $image->getKey(),
            'ref' => $image->ref(),
            'url' => BlockRenderer::imageAddress($site, $image),
            'alt' => $image->alt,
        ];
    }

    /**
     * Whether the draft differs from what guests see.
     */
    public function unpublished(Website $site): bool
    {
        return self::normalised(SiteContent::draft($site)) !== self::normalised(SiteContent::published($site));
    }

    /**
     * Photos of the homes on the site, for the photo picker.
     *
     * @return list<array{ref: string, url: string, listing: string}>
     */
    private function photos(Website $site): array
    {
        $photos = [];

        foreach ($this->catalogue->listings($site) as $listing) {
            /** @var Listing $listing */
            foreach (array_slice($this->catalogue->photos($site, $listing), 0, self::PHOTOS_PER_HOME) as $photo) {
                $photos[] = ['ref' => 'photo:'.$photo['photo']->getKey(), 'url' => $photo['url'], 'listing' => $listing->displayTitle()];
            }
        }

        return $photos;
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{key: string, label: string}>
     */
    private static function options(array $labels): array
    {
        $options = [];

        foreach ($labels as $key => $label) {
            $options[] = ['key' => (string) $key, 'label' => $label];
        }

        return $options;
    }

    /**
     * The same document in the same order, whichever way its keys were
     * stored, so two equal documents compare equal.
     */
    private static function normalised(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::normalised(...), $value);
    }
}
