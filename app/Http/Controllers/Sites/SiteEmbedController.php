<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sites;

use App\Domain\Websites\DataObjects\SearchCriteria;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Services\WebsiteCatalogue;
use App\Domain\Websites\Support\SiteFrames;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The pieces a client places on their own store: the search bar, the homes
 * grid, one home's booking box, and the search results the pop-up shows when
 * the page has no grid. The home's page, checkout and booking in the pop-up
 * are the site's own pages drawn for a frame ({@see SitePageController}).
 */
class SiteEmbedController extends Controller
{
    public function __construct(private readonly WebsiteCatalogue $catalogue) {}

    public function search(Request $request, string $site): Response
    {
        $website = $this->catalogue->live($site);

        return $this->framed($website, view('sites.embed.search', [
            'site' => $website,
            'catalogue' => $this->catalogue,
            'criteria' => SearchCriteria::fromRequest($request),
            'embed' => 'piece',
        ]));
    }

    public function homes(Request $request, string $site): Response
    {
        return $this->grid($request, $site, 'piece');
    }

    /**
     * The homes that suit a search made on a store page with no grid of its
     * own, shown in the pop-up.
     */
    public function results(Request $request, string $site): Response
    {
        return $this->grid($request, $site, 'popup');
    }

    public function book(Request $request, string $site, string $listing): Response
    {
        $website = $this->catalogue->live($site);
        $found = $this->catalogue->listing($website, $listing);
        $criteria = SearchCriteria::fromRequest($request);
        $availability = $this->catalogue->availability($found, $criteria);

        return $this->framed($website, view('sites.embed.book', [
            'site' => $website,
            'catalogue' => $this->catalogue,
            'listing' => $found,
            'criteria' => $criteria,
            'total' => $availability['total'],
            'reasons' => $availability['reasons'],
            'embed' => 'piece',
        ]));
    }

    private function grid(Request $request, string $site, string $embed): Response
    {
        $website = $this->catalogue->live($site);
        $criteria = SearchCriteria::fromRequest($request);

        return $this->framed($website, view('sites.embed.homes', [
            'site' => $website,
            'catalogue' => $this->catalogue,
            'criteria' => $criteria,
            'results' => $this->catalogue->search($website, $criteria),
            'embed' => $embed,
        ]));
    }

    private function framed(Website $site, View $view): Response
    {
        return response($view)->withHeaders(SiteFrames::headers($site));
    }
}
