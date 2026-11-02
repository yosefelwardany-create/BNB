<?php

declare(strict_types=1);

namespace App\Domain\Websites\Support;

use App\Domain\Listings\Models\Listing;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Services\WebsiteCatalogue;

/**
 * What the platform owner pastes into a client's store: the script line,
 * once, and a tag wherever a piece should appear.
 *
 * Written here rather than in the editor so the tags always name the
 * addresses the embed pages answer to: a booking box names a home by the
 * same address its page on the site has.
 */
final class EmbedSnippets
{
    public function __construct(private readonly WebsiteCatalogue $catalogue) {}

    /**
     * @return array{script: string, search: string, homes: string, book: list<array{listing_id: string, title: string, tag: string}>}
     */
    public function for(Website $site): array
    {
        $tag = fn (string $piece, string $extra = ''): string => sprintf(
            '<div data-habitat-site="%s" data-habitat-piece="%s"%s></div>',
            e($site->slug),
            $piece,
            $extra,
        );

        return [
            'script' => '<script src="'.e(url('/sites/embed.js')).'" defer></script>',
            'search' => $tag('search'),
            'homes' => $tag('homes'),
            'book' => $this->catalogue->listings($site)
                ->map(fn (Listing $listing): array => [
                    'listing_id' => $listing->getKey(),
                    'title' => $listing->displayTitle(),
                    'tag' => $tag('book', ' data-habitat-home="'.e($this->catalogue->slugFor($site, $listing)).'"'),
                ])
                ->values()
                ->all(),
        ];
    }
}
