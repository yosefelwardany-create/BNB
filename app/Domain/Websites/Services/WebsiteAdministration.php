<?php

declare(strict_types=1);

namespace App\Domain\Websites\Services;

use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Services\ListingService;
use App\Domain\Properties\Enums\ListingStatus;
use App\Domain\Properties\Enums\PropertyStatus;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Websites\Models\Website;
use App\Http\Resources\WebsiteResource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What both the platform and a client change about a site outside its pages:
 * its details, the homes it shows, and the homes it could show.
 *
 * Listings are read across accounts here, which is why every read of one goes
 * through {@see TenantContext::withoutScope()} and is then held to the site's
 * rules explicitly — a client site takes only its own client's listings.
 */
final class WebsiteAdministration
{
    private const DRAFT = 'Not published yet.';

    private const PAUSED = 'Paused.';

    /** What a listing's readiness reads. */
    private const READINESS = ['property.photos', 'photos.propertyPhoto', 'unitType', 'unit'];

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly ListingService $listings,
        private readonly PropertyService $properties,
    ) {}

    /**
     * The site's name and its contact and social details; the stored image
     * paths and everything else stay as they are.
     *
     * @param  array{name?: string, settings?: array<string, mixed>}  $data  already validated
     */
    public function updateDetails(Website $website, array $data): Website
    {
        $changes = array_intersect_key($data, array_flip(['name']));

        if (isset($data['settings'])) {
            $edits = array_intersect_key($data['settings'], array_flip(WebsiteResource::SETTINGS));
            $changes['settings'] = array_replace($website->settings ?? [], $edits);
        }

        $website->forceFill($changes)->save();

        return $website;
    }

    /**
     * Replace the site's homes: their choice, order and which are featured.
     *
     * @param  list<array{listing_id: string, is_featured?: bool}>  $rows
     *
     * @throws ValidationException
     */
    public function replaceListings(Website $website, array $rows): void
    {
        $rows = collect($rows)->values();
        $listings = $this->tenancy->withoutScope(fn (): Collection => Listing::query()
            ->bookable()
            ->with('organization')
            ->whereIn('id', $rows->pluck('listing_id'))
            ->get()
            ->keyBy('id'));

        $errors = [];

        foreach ($rows as $index => $row) {
            $listing = $listings->get($row['listing_id']);

            if ($listing === null || ! $listing->organization?->status->allowsAccess()) {
                $errors["listings.{$index}.listing_id"] = 'Only a published listing of an active client account can be shown.';
            } elseif (! $website->isBrand() && $listing->organization_id !== $website->organization_id) {
                $errors["listings.{$index}.listing_id"] = 'A client site shows only its own client\'s listings.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($website, $rows, $listings): void {
            $website->listings()->delete();

            foreach ($rows as $position => $row) {
                $website->listings()->create([
                    'listing_id' => $row['listing_id'],
                    'organization_id' => $listings->get($row['listing_id'])->organization_id,
                    'position' => $position,
                    'is_featured' => (bool) ($row['is_featured'] ?? false),
                ]);
            }
        });

    }

    /**
     * Every home a site could show, each with what it still needs.
     *
     * Not only the ones already on sale: a client whose homes are still
     * drafts — every new or imported property starts as one — would see an
     * empty list and no reason for it.
     *
     * @return list<array<string, mixed>>
     */
    public function listingOptions(?string $organizationId): array
    {
        $organizationId ??= '';

        $listings = $this->tenancy->withoutScope(fn (): Collection => Listing::query()
            ->where('status', '!=', ListingStatus::Archived->value)
            ->whereHas('property', fn ($query) => $query->where('status', '!=', PropertyStatus::Archived->value))
            ->with(['organization', 'property.photos', 'photos.propertyPhoto', 'unitType', 'unit'])
            ->when($organizationId !== '', fn ($query) => $query->where('organization_id', $organizationId))
            ->get()
            ->filter(fn (Listing $listing): bool => (bool) $listing->organization?->status->allowsAccess()));

        return $listings
            ->sortBy(fn (Listing $listing): string => $listing->organization?->name.'|'.$listing->displayTitle())
            ->map(fn (Listing $listing): array => $this->option($listing))
            ->values()->all();
    }

    /**
     * Put a home on sale so a site can show it: its property activated when
     * that is all it lacks, and the listing published.
     *
     * @return array<string, mixed>|null the home as the picker shows it, or null when there is none
     *
     * @throws ValidationException
     */
    public function publishListing(string $listing): ?array
    {
        $found = $this->tenancy->withoutScope(fn (): ?Listing => Listing::query()->with('organization')->find($listing));

        if ($found === null || $found->organization === null || ! $found->organization->status->allowsAccess()) {
            return null;
        }

        $published = $this->tenancy->runAs($found->organization, function () use ($found): Listing {
            return DB::transaction(function () use ($found): Listing {
                $listing = Listing::query()->with(self::READINESS)->lockForUpdate()->findOrFail($found->getKey());
                $missing = array_values(array_filter($this->needs($listing), fn (string $need): bool => ! in_array($need, [self::DRAFT, self::PAUSED], true)));

                if ($missing !== []) {
                    throw ValidationException::withMessages(['listing' => sprintf('%s is not ready to publish: %s', $listing->displayTitle(), implode(' ', $missing))]);
                }

                if (! $listing->property->isBookable()) {
                    $this->properties->activate($listing->property);
                }

                if ($listing->status !== ListingStatus::Published) {
                    $this->listings->publish($listing->load(self::READINESS));
                }

                return $listing->fresh(self::READINESS);
            });
        });

        return $this->option($published->load('organization'));
    }

    /**
     * The site with what its resource reads: its account and its homes.
     */
    public function withDetails(Website $website): Website
    {
        return $this->tenancy->withoutScope(fn (): Website => $website->load([
            'organization',
            'listings.organization',
            'listings.listing.property',
        ]));
    }

    /**
     * A home as the site's picker shows it.
     *
     * @return array<string, mixed>
     */
    private function option(Listing $listing): array
    {
        // On sale, as a site's own query has it: published, its property active.
        $ready = $listing->status === ListingStatus::Published && (bool) $listing->property?->isBookable();
        $needs = $ready ? [] : $this->needs($listing);

        return [
            'listing_id' => $listing->getKey(),
            'title' => $listing->displayTitle(),
            'organization_id' => $listing->organization_id,
            'organization_name' => $listing->organization?->name,
            'city' => $listing->property?->city,
            'ready' => $ready,
            'needs' => $needs,
            // Nothing in the way but being put on sale, which this screen can do.
            'can_publish' => ! $ready && array_diff($needs, [self::DRAFT, self::PAUSED]) === [],
            'property_active' => (bool) $listing->property?->isBookable(),
        ];
    }

    /**
     * What stands between a home and a site, in sentences: the property's
     * own gaps when it is not active, then the listing's. Empty when it is
     * on sale.
     *
     * @return list<string>
     */
    private function needs(Listing $listing): array
    {
        $property = $listing->property;
        $needs = [];

        if ($listing->status === ListingStatus::Draft) {
            $needs[] = self::DRAFT;
        } elseif ($listing->status === ListingStatus::Paused) {
            $needs[] = self::PAUSED;
        }

        $propertyGaps = $property === null || $property->isBookable() ? [] : $this->properties->activationBlockers($property);

        foreach ($propertyGaps as $gap) {
            $needs[] = match (true) {
                str_ends_with($gap, ' is required') => 'The property needs '.substr($gap, 0, -strlen(' is required')).'.',
                default => ucfirst($gap).'.',
            };
        }

        foreach ($this->listings->publicationBlockers($listing) as $blocker) {
            // Said already, as the property's own gaps.
            if (str_contains($blocker, 'rather than active')
                || ($blocker === 'a positive base rate is required' && in_array('a base nightly rate is required', $propertyGaps, true))
                || in_array($blocker, $propertyGaps, true)) {
                continue;
            }

            $needs[] = ucfirst($blocker).'.';
        }

        return array_values(array_unique($needs));
    }
}
