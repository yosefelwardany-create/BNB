<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sites;

use App\Domain\Websites\DataObjects\SearchCriteria;
use App\Domain\Websites\Services\WebsiteCatalogue;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A booking website's pages, as HTML.
 *
 * Rendered on the server so search engines and link previews read real
 * content and a phone gets a finished page on its first request.
 */
class SitePageController extends Controller
{
    public function __construct(private readonly WebsiteCatalogue $catalogue) {}

    public function home(string $site): View
    {
        $website = $this->catalogue->live($site);

        return view('sites.default.home', [
            'site' => $website,
            'catalogue' => $this->catalogue,
            'featured' => $this->catalogue->featured($website),
            'criteria' => new SearchCriteria,
        ]);
    }

    public function search(Request $request, string $site): View
    {
        $website = $this->catalogue->live($site);
        $criteria = SearchCriteria::fromRequest($request);

        return view('sites.default.search', [
            'site' => $website,
            'catalogue' => $this->catalogue,
            'criteria' => $criteria,
            'results' => $this->catalogue->search($website, $criteria),
        ]);
    }

    public function property(Request $request, string $site, string $listing): View
    {
        $website = $this->catalogue->live($site);
        $found = $this->catalogue->listing($website, $listing);
        $criteria = SearchCriteria::fromRequest($request);
        $availability = $this->catalogue->availability($found, $criteria);

        return view('sites.default.property', [
            'site' => $website,
            'catalogue' => $this->catalogue,
            'listing' => $found,
            'criteria' => $criteria,
            'total' => $availability['total'],
            'reasons' => $availability['reasons'],
            'reviews' => $this->catalogue->reviews($found),
        ]);
    }
}
