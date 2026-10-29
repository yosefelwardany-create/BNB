<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sites;

use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Services\WebsiteCatalogue;
use App\Domain\Websites\Support\WebsiteMedia;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The files beneath a booking website: its photos, its own images, and what
 * search engines read.
 *
 * A photo is served only when it belongs to a listing the site shows, so a
 * site's photo route is never a way to fetch another account's pictures by
 * guessing ids.
 */
class SiteAssetController extends Controller
{
    /** A day in browsers and shared caches; a replaced image has a new address. */
    private const CACHE = 'public, max-age=86400';

    public function __construct(
        private readonly WebsiteCatalogue $catalogue,
        private readonly TenantContext $tenancy,
    ) {}

    public function photo(string $site, string $photo): StreamedResponse|RedirectResponse
    {
        $website = $this->catalogue->live($site);

        $found = $this->tenancy->withoutScope(fn (): ?PropertyPhoto => PropertyPhoto::query()->find($photo));

        abort_if($found === null || ! $this->catalogue->showsPhoto($website, $found), 404);

        if ($found->external_url !== null) {
            $url = $this->catalogue->photoUrl($website, $found);

            abort_if($url === null, 404);

            return redirect()->away($url);
        }

        $disk = Storage::disk($found->disk);

        abort_unless($disk->exists($found->path), 404);

        return $disk->response($found->path, null, ['Cache-Control' => self::CACHE]);
    }

    /**
     * A site's logo or home-page photo, for any site that exists, live or
     * not: the Websites screen previews a draft's images from here, and a logo
     * is not a secret.
     */
    public function media(string $site, string $kind): StreamedResponse
    {
        $website = Website::query()->where('slug', $site)->firstOrFail();
        $path = in_array($kind, WebsiteMedia::KINDS, true) ? $website->setting($kind.'_path') : null;

        abort_unless(is_string($path) && Storage::disk(WebsiteMedia::DISK)->exists($path), 404);

        return Storage::disk(WebsiteMedia::DISK)->response($path, null, ['Cache-Control' => self::CACHE]);
    }

    public function sitemap(string $site): Response
    {
        $website = $this->catalogue->live($site);

        $urls = [$website->url(), $website->url('search')];

        foreach ($this->catalogue->listings($website) as $listing) {
            $urls[] = $website->url('stay/'.$this->catalogue->slugFor($website, $listing));
        }

        return response()
            ->view('sites.sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml');
    }

    public function robots(string $site): Response
    {
        $website = $this->catalogue->live($site);

        return response("User-agent: *\nAllow: /\nSitemap: ".$website->url('sitemap.xml')."\n")
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
