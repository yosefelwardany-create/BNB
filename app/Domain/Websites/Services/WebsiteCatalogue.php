<?php

declare(strict_types=1);

namespace App\Domain\Websites\Services;

use App\Domain\Availability\DataObjects\AvailabilityRequest;
use App\Domain\Availability\Services\AvailabilityEngine;
use App\Domain\Integrations\Support\HostexData;
use App\Domain\Listings\Models\Listing;
use App\Domain\Pricing\DataObjects\PricingContext;
use App\Domain\Pricing\Services\PricingEngine;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Reviews\Models\Review;
use App\Domain\Websites\DataObjects\SearchCriteria;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteListing;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a booking website shows, and nothing else.
 *
 * The read side of a public site, used without a signed-in user. Listings are
 * read across client accounts — a brand site mixes them — so every read goes
 * through {@see TenantContext::withoutScope()} and is held to the site's rules
 * here: a live site, only the listings attached to it, only those still
 * published, with an active property, in an active client account. Anything
 * a page needs is loaded here, because outside these reads no tenant is bound
 * and a scoped query would be refused.
 *
 * Prices and availability are the engines' own answers, asked inside the
 * listing's account with {@see TenantContext::runAs()}, so the site never
 * shows a price the rest of Habitat would not charge.
 */
class WebsiteCatalogue
{
    /** @var array<string, Collection<int, Listing>> */
    private array $listings = [];

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly AvailabilityEngine $availability,
        private readonly PricingEngine $pricing,
    ) {}

    /**
     * The live site at this address, or not found.
     */
    public function live(string $slug): Website
    {
        $site = Website::query()->where('slug', $slug)->first();

        abort_if($site === null || ! $site->isLive(), 404);

        return $site;
    }

    /**
     * The site's listings that a guest may see, in the site's order.
     *
     * @return Collection<int, Listing>
     */
    public function listings(Website $site): Collection
    {
        return $this->listings[$site->getKey()] ??= $this->tenancy->withoutScope(function () use ($site): Collection {
            $rows = $site->listings()->get();

            $listings = Listing::query()
                ->bookable()
                ->whereIn('id', $rows->pluck('listing_id'))
                ->with([
                    'organization',
                    'property.photos',
                    'property.amenities',
                    'photos.propertyPhoto',
                    'amenityOverrides',
                ])
                ->get()
                ->keyBy('id');

            return $rows
                ->map(function (WebsiteListing $row) use ($listings): ?Listing {
                    $listing = $listings->get($row->listing_id);

                    return $listing?->setRelation('websiteRow', $row);
                })
                ->filter(fn (?Listing $listing): bool => $listing !== null
                    && (bool) $listing->organization?->status->allowsAccess())
                ->values();
        });
    }

    /**
     * @return Collection<int, Listing>
     */
    public function featured(Website $site): Collection
    {
        $listings = $this->listings($site);
        $featured = $listings->filter(fn (Listing $listing): bool => (bool) $listing->getRelation('websiteRow')?->is_featured);

        return ($featured->isEmpty() ? $listings : $featured)->take(6)->values();
    }

    /**
     * The site's listings that suit the search, each with its price.
     *
     * @return list<array{listing: Listing, total: ?Money, nightly_from: Money}>
     */
    public function search(Website $site, SearchCriteria $criteria): array
    {
        $results = [];

        foreach ($this->listings($site) as $listing) {
            if (! $this->suits($listing, $criteria)) {
                continue;
            }

            $total = null;

            if ($criteria->hasDates()) {
                $total = $this->priceFor($listing, $criteria);

                if ($total === null) {
                    continue;
                }
            }

            $nightly = $total === null
                ? $listing->baseRate()
                : Money::of(intdiv($total->minorUnits, max(1, $criteria->nights())), $total->currency);

            if (! $this->withinPrice($nightly, $criteria)) {
                continue;
            }

            $results[] = ['listing' => $listing, 'total' => $total, 'nightly_from' => $listing->baseRate()];
        }

        return $results;
    }

    /**
     * One listing on the site by its address, with everything its page shows,
     * or not found.
     */
    public function listing(Website $site, string $slug): Listing
    {
        $listing = $this->listings($site)->first(fn (Listing $candidate): bool => $this->slugFor($site, $candidate) === $slug);

        abort_if($listing === null, 404);

        // loadMissing, not load: reloading `property` for its rooms would drop
        // the photos and amenities already read with it, and the page would
        // then ask for them again with no account bound.
        return $this->tenancy->withoutScope(fn (): Listing => $listing->loadMissing([
            'property.rooms',
            'cancellationPolicy',
            'property.cancellationPolicy',
        ]));
    }

    /**
     * Published reviews of the listing's property, newest first.
     *
     * @return Collection<int, Review>
     */
    public function reviews(Listing $listing, int $limit = 6): Collection
    {
        return $this->tenancy->withoutScope(fn (): Collection => Review::query()
            ->where('property_id', $listing->property_id)
            ->where('direction', Review::GUEST_TO_HOST)
            ->whereIn('status', [Review::PUBLISHED, Review::RESPONDED])
            ->whereNotNull('public_comment')
            ->latest('submitted_at')
            ->limit($limit)
            ->get());
    }

    /**
     * The listing's address on this site: its own slug, unless another listing
     * on the same site has it too, in which case its id.
     */
    public function slugFor(Website $site, Listing $listing): string
    {
        $slug = (string) $listing->slug;
        $sharing = $this->listings($site)->filter(fn (Listing $other): bool => (string) $other->slug === $slug)->count();

        return $slug !== '' && $sharing === 1 ? $slug : $listing->getKey();
    }

    /**
     * Where a browser loads a photo from: the channel's own image for one
     * imported with an address, otherwise the site's photo route.
     */
    public function photoUrl(Website $site, PropertyPhoto $photo): string
    {
        if ($photo->external_url !== null) {
            return HostexData::imageUrl($photo->external_url);
        }

        return $site->url('photos/'.$photo->getKey());
    }

    /**
     * Whether a photo belongs to a listing this site shows.
     */
    public function showsPhoto(Website $site, PropertyPhoto $photo): bool
    {
        return $this->listings($site)->contains(fn (Listing $listing): bool => $listing->property_id === $photo->property_id);
    }

    /**
     * Whether the listing is free for the dates, and its total if so.
     *
     * Asked inside the listing's own account, on a freshly read single model,
     * so the engines read what they need from the right account. A listing the
     * engines cannot price is treated as unavailable and logged, never shown
     * with a made-up price.
     */
    public function priceFor(Listing $listing, SearchCriteria $criteria): ?Money
    {
        if (! $criteria->hasDates() || $listing->organization === null) {
            return null;
        }

        try {
            return $this->tenancy->runAs($listing->organization, function () use ($listing, $criteria): ?Money {
                $fresh = Listing::query()->with('property')->find($listing->getKey());

                if ($fresh === null || $fresh->property === null) {
                    return null;
                }

                $available = $this->availability->check(new AvailabilityRequest(
                    property: $fresh->property,
                    checkIn: $criteria->checkIn,
                    checkOut: $criteria->checkOut,
                    listing: $fresh,
                    guests: $criteria->guests,
                ));

                if (! $available->isAvailable) {
                    return null;
                }

                return $this->pricing->quote(new PricingContext(
                    listing: $fresh,
                    checkIn: $criteria->checkIn,
                    checkOut: $criteria->checkOut,
                    adults: $criteria->guests,
                ))->grandTotal();
            });
        } catch (Throwable $e) {
            Log::warning('A website listing could not be priced.', [
                'listing_id' => $listing->getKey(),
                'exception_type' => $e::class,
            ]);

            return null;
        }
    }

    // ------------------------------------------------------------------

    private function suits(Listing $listing, SearchCriteria $criteria): bool
    {
        if ($listing->maxOccupancy() < $criteria->guests) {
            return false;
        }

        if ($criteria->bedrooms !== null && (int) $listing->resolved('bedrooms') < $criteria->bedrooms) {
            return false;
        }

        if ($criteria->bathrooms !== null && (float) $listing->resolved('bathrooms') < $criteria->bathrooms) {
            return false;
        }

        if ($criteria->amenities !== []) {
            $keys = array_map(fn ($amenity): string => (string) $amenity->key, $listing->effectiveAmenities());

            if (array_diff($criteria->amenities, $keys) !== []) {
                return false;
            }
        }

        return true;
    }

    private function withinPrice(Money $nightly, SearchCriteria $criteria): bool
    {
        $exponent = 10 ** Money::exponent($nightly->currency);

        if ($criteria->minPrice !== null && $nightly->minorUnits < $criteria->minPrice * $exponent) {
            return false;
        }

        return $criteria->maxPrice === null || $nightly->minorUnits <= $criteria->maxPrice * $exponent;
    }
}
