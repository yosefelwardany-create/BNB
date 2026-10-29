<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sites;

use App\Domain\Listings\Models\Listing;
use App\Domain\Pricing\Models\Quote;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Websites\DataObjects\SearchCriteria;
use App\Domain\Websites\Models\WebsiteCheckout;
use App\Domain\Websites\Services\WebsiteCatalogue;
use App\Domain\Websites\Services\WebsitePayments;
use App\Domain\Websites\Services\WebsiteQuotes;
use App\Domain\Websites\Support\SitePayments;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * A booking website's pages, as HTML.
 *
 * Rendered on the server so search engines and link previews read real
 * content and a phone gets a finished page on its first request.
 */
class SitePageController extends Controller
{
    public function __construct(
        private readonly WebsiteCatalogue $catalogue,
        private readonly WebsiteQuotes $quotes,
        private readonly WebsitePayments $payments,
        private readonly TenantContext $tenancy,
    ) {}

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

    /**
     * The page a guest confirms a quoted stay on.
     *
     * A quote that has lapsed or been used sends the guest back to choose
     * again rather than showing a price that can no longer be had.
     */
    public function checkout(string $site, string $quote): View
    {
        $website = $this->catalogue->live($site);
        $found = $this->tenancy->withoutScope(fn (): ?Quote => Quote::query()->find($quote));
        $listing = $found === null ? null : $this->catalogue->listingById($website, (string) $found->listing_id);

        abort_if($found === null || $listing === null, 404);

        $open = $found->converted_reservation_id === null && $found->expires_at?->isFuture() === true;

        return view('sites.default.checkout', [
            'site' => $website,
            'catalogue' => $this->catalogue,
            'listing' => $listing,
            'quote' => $found,
            'priced' => $open ? $this->quotes->present($found->setRelation('listing', $listing)) : null,
            'online' => SitePayments::online(),
        ]);
    }

    /**
     * The guest's own page for their booking, reached by its secret link.
     */
    public function booking(Request $request, string $site, string $token): Response
    {
        $website = $this->catalogue->live($site);

        $checkout = WebsiteCheckout::query()
            ->where('website_id', $website->getKey())
            ->where('access_token', $token)
            ->first();

        abort_if($checkout === null, 404);

        [$listing, $reservation] = $this->tenancy->withoutScope(fn (): array => [
            $checkout->listing_id === null ? null : Listing::query()->with('property')->find($checkout->listing_id),
            $checkout->reservation_id === null ? null : Reservation::query()->find($checkout->reservation_id),
        ]);

        $holding = $checkout->status === WebsiteCheckout::STATUS_HOLDING;

        // Back from Stripe, ahead of its webhook: the payment is being
        // confirmed, and the page waits for it rather than asking again.
        $confirming = $holding && in_array($request->query('redirect_status'), ['succeeded', 'processing'], true);

        return response()
            ->view('sites.default.booking', [
                'site' => $website,
                'catalogue' => $this->catalogue,
                'checkout' => $checkout,
                'listing' => $listing,
                'reservation' => $reservation,
                'confirming' => $confirming,
                'failedAtStripe' => $holding && $request->query('redirect_status') === 'failed',
                'clientSecret' => $holding && ! $confirming && SitePayments::online() ? $this->payments->clientSecret($checkout) : null,
            ])
            // Never kept by a shared cache: the page is one guest's booking.
            ->header('Cache-Control', 'private, no-store');
    }
}
