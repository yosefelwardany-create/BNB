<?php

declare(strict_types=1);

namespace App\Domain\Websites\Services;

use App\Domain\Availability\DataObjects\AvailabilityRequest;
use App\Domain\Availability\Services\AvailabilityEngine;
use App\Domain\Integrations\Support\HostexData;
use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Models\ListingPhoto;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Pricing\DataObjects\PricingContext;
use App\Domain\Pricing\Services\PricingEngine;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Domain\Reviews\Models\Review;
use App\Domain\Websites\DataObjects\SearchCriteria;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Models\WebsiteListing;
use App\Support\Money\Money;
use App\Support\Tenancy\TenantContext;
use Closure;
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

        return $this->withDetails($listing);
    }

    /**
     * One of the site's listings by its id, as a quote names it; null when the
     * site does not show it.
     */
    public function listingById(Website $site, string $id): ?Listing
    {
        $listing = $this->listings($site)->first(fn (Listing $candidate): bool => $candidate->getKey() === $id);

        return $listing === null ? null : $this->withDetails($listing);
    }

    /**
     * What a property page or checkout reads beyond the search results.
     *
     * loadMissing, not load: reloading `property` for its rooms would drop the
     * photos and amenities already read with it, and the page would then ask
     * for them again with no account bound.
     */
    private function withDetails(Listing $listing): Listing
    {
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
     * imported with an address, otherwise the site's photo route. Null for an
     * imported address that is not a safe https image, which is left out
     * rather than linked.
     */
    public function photoUrl(Website $site, PropertyPhoto $photo): ?string
    {
        if ($photo->external_url !== null) {
            return HostexData::imageUrl($photo->external_url);
        }

        return $site->url('photos/'.$photo->getKey());
    }

    /**
     * The listing's photos a page can show, in order, each with its address.
     *
     * @return list<array{photo: PropertyPhoto, url: string}>
     */
    public function photos(Website $site, Listing $listing): array
    {
        $photos = [];

        foreach ($listing->effectivePhotos() as $photo) {
            $url = $this->photoUrl($site, $photo);

            if ($url !== null) {
                $photos[] = ['photo' => $photo, 'url' => $url];
            }
        }

        return $photos;
    }

    /**
     * Whether a photo is one a listing on this site shows.
     *
     * Asked once per image, a dozen times a page, so it reads only the
     * listings of the photo's own property rather than the whole site. A
     * listing with its own selection shows only those it has not hidden; one
     * without shows all its property's photos — as {@see Listing::effectivePhotos()}.
     */
    public function showsPhoto(Website $site, PropertyPhoto $photo): bool
    {
        $open = collect(OrganizationStatus::cases())
            ->filter(fn (OrganizationStatus $status): bool => $status->allowsAccess())
            ->map(fn (OrganizationStatus $status): string => $status->value)
            ->values()
            ->all();

        return $this->tenancy->withoutScope(fn (): bool => Listing::query()
            ->bookable()
            ->where('property_id', $photo->property_id)
            ->whereIn('id', WebsiteListing::query()->where('website_id', $site->getKey())->select('listing_id'))
            ->whereHas('organization', fn ($query) => $query->whereIn('status', $open))
            ->with('photos')
            ->get()
            ->contains(fn (Listing $listing): bool => $listing->photos->isEmpty()
                || $listing->photos->contains(fn (ListingPhoto $selected): bool => ! $selected->is_hidden
                    && $selected->property_photo_id === $photo->getKey())));
    }

    /**
     * The listing's total for the dates, or null when it cannot be booked
     * for them.
     */
    public function priceFor(Listing $listing, SearchCriteria $criteria): ?Money
    {
        return $this->availability($listing, $criteria)['total'];
    }

    /**
     * Whether the listing can be booked for the dates and guests, its total if
     * so, and if not, why, in the guest's words.
     *
     * @return array{total: ?Money, reasons: list<string>}
     */
    public function availability(Listing $listing, SearchCriteria $criteria): array
    {
        $outcome = $this->whenBookable($listing, $criteria, fn (Listing $fresh): Money => $this->pricing->quote(new PricingContext(
            listing: $fresh,
            checkIn: $criteria->checkIn,
            checkOut: $criteria->checkOut,
            adults: $criteria->guests,
        ))->grandTotal());

        return ['total' => $outcome['result'], 'reasons' => $outcome['reasons']];
    }

    /**
     * Run $then when the listing can be booked for the dates and guests;
     * otherwise say why, in the guest's words.
     *
     * $then runs inside the listing's own account, on a freshly read single
     * model with its property, so the engines read what they need from the
     * right account. A listing that fails there is treated as unavailable and
     * logged, never shown with a made-up price.
     *
     * @template TResult
     *
     * @param  Closure(Listing): TResult  $then
     * @return array{result: TResult|null, reasons: list<string>}
     */
    public function whenBookable(Listing $listing, SearchCriteria $criteria, Closure $then): array
    {
        if (! $criteria->hasDates() || $listing->organization === null) {
            return ['result' => null, 'reasons' => []];
        }

        if ($criteria->guests > $listing->maxOccupancy()) {
            return ['result' => null, 'reasons' => [sprintf('This home sleeps up to %d guests.', $listing->maxOccupancy())]];
        }

        try {
            return $this->tenancy->runAs($listing->organization, function () use ($listing, $criteria, $then): array {
                $fresh = Listing::query()->with('property')->find($listing->getKey());

                if ($fresh === null || $fresh->property === null) {
                    return ['result' => null, 'reasons' => ['This home is not taking bookings right now.']];
                }

                $available = $this->availability->check(new AvailabilityRequest(
                    property: $fresh->property,
                    checkIn: $criteria->checkIn,
                    checkOut: $criteria->checkOut,
                    listing: $fresh,
                    guests: $criteria->guests,
                ));

                if (! $available->isAvailable) {
                    return ['result' => null, 'reasons' => self::guestReasons($available->reasons)];
                }

                return ['result' => $then($fresh), 'reasons' => []];
            });
        } catch (Throwable $e) {
            Log::warning('A website listing could not be priced.', [
                'listing_id' => $listing->getKey(),
                'exception_type' => $e::class,
            ]);

            return ['result' => null, 'reasons' => ['Those dates are not available.']];
        }
    }

    /**
     * The availability engine's reasons a guest may read.
     *
     * The restriction checker's sentences are written for guests; the rest
     * (a listing's status, a channel's import) is summed up rather than shown.
     *
     * @param  list<string>  $reasons
     * @return list<string>
     */
    public static function guestReasons(array $reasons): array
    {
        $shown = array_values(array_filter(
            $reasons,
            fn (string $reason): bool => ! str_contains($reason, 'rather than active') && ! str_contains($reason, 'Hostex'),
        ));

        return $shown === [] ? ['Those dates are not available.'] : array_slice($shown, 0, 2);
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
