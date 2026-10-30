<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Services\ListingService;
use App\Domain\Properties\Enums\ListingStatus;
use App\Domain\Properties\Enums\PropertyStatus;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Websites\Content\SiteContent;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Services\WebsiteReadiness;
use App\Domain\Websites\Support\WebsiteMedia;
use App\Http\Controllers\Controller;
use App\Http\Resources\WebsiteResource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The platform owner's booking websites.
 *
 * Behind `platform-admin`: a brand site spans client accounts, and in the
 * managed service a client's own login reads and never writes. Listings are
 * read across accounts here, which is why every read of one goes through
 * {@see TenantContext::withoutScope()} and is then held to the site's rules
 * explicitly — a client site takes only its own client's listings.
 */
class WebsiteController extends Controller
{
    private const DRAFT = 'Not published yet.';

    private const PAUSED = 'Paused.';

    /** What a listing's readiness reads. */
    private const READINESS = ['property.photos', 'photos.propertyPhoto', 'unitType', 'unit'];

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly WebsiteReadiness $readiness,
        private readonly ListingService $listings,
        private readonly PropertyService $properties,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $sites = $this->tenancy->withoutScope(fn (): Collection => $this->withDetails(
            Website::query()->orderBy('name')->get(),
        ));

        return WebsiteResource::collection($sites);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in([Website::KIND_BRAND, Website::KIND_CLIENT])],
            'organization_id' => [
                Rule::requiredIf($request->input('kind') === Website::KIND_CLIENT),
                'prohibited_if:kind,'.Website::KIND_BRAND,
                'nullable', 'string', Rule::exists('organizations', 'id'),
            ],
            'name' => ['required', 'string', 'max:120'],
            'slug' => $this->slugRules(),
        ]);

        $site = Website::query()->create($data + ['status' => Website::STATUS_DRAFT, 'settings' => []]);

        // Its editor opens on a page already laid out; guests see it once
        // it is published.
        $site->forceFill(['draft_content' => SiteContent::starter($site)])->save();

        return $this->respond($site, 201);
    }

    public function show(Website $website): JsonResponse
    {
        return $this->respond($website);
    }

    public function update(Request $request, Website $website): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'slug' => ['sometimes', ...$this->slugRules($website)],
            'hold_minutes' => ['sometimes', 'integer', 'min:10', 'max:120'],
            'request_hours' => ['sometimes', 'integer', 'min:1', 'max:72'],
            'settings' => ['sometimes', 'array'],
            'settings.contact_email' => ['nullable', 'email', 'max:190'],
            'settings.contact_phone' => ['nullable', 'string', 'max:40'],
            'settings.address_line' => ['nullable', 'string', 'max:240'],
            'settings.instagram_url' => ['nullable', 'url', 'max:240'],
            'settings.facebook_url' => ['nullable', 'url', 'max:240'],
        ]);

        if (isset($data['settings'])) {
            // Only the editable keys; the stored image paths stay as they are.
            $edits = array_intersect_key($data['settings'], array_flip(WebsiteResource::SETTINGS));
            $data['settings'] = array_replace($website->settings ?? [], $edits);
        }

        $website->forceFill($data)->save();

        return $this->respond($website);
    }

    /**
     * Replace the site's properties: their choice, order and which are featured.
     */
    public function listings(Request $request, Website $website): JsonResponse
    {
        $data = $request->validate([
            'listings' => ['present', 'array', 'max:500'],
            'listings.*.listing_id' => ['required', 'string', 'distinct'],
            'listings.*.is_featured' => ['sometimes', 'boolean'],
        ]);

        $rows = collect($data['listings'])->values();
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

        return $this->respond($website);
    }

    public function publish(Website $website): JsonResponse
    {
        // Only a site with nothing to show is kept back; one without payments
        // goes live asking guests to get in touch.
        $refusal = $this->readiness->publishingRefusal($website);

        abort_if($refusal !== null, 422, (string) $refusal);

        $website->forceFill(['status' => Website::STATUS_LIVE, 'published_at' => $website->published_at ?? now()])->save();

        return $this->respond($website);
    }

    public function unpublish(Website $website): JsonResponse
    {
        $website->forceFill(['status' => Website::STATUS_DRAFT])->save();

        return $this->respond($website);
    }

    public function media(Request $request, Website $website): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(WebsiteMedia::KINDS)],
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:8192'],
        ]);

        WebsiteMedia::store($website, $data['file'], $data['kind']);

        return $this->respond($website->fresh());
    }

    /**
     * What may be put on a site: every published listing of an active client
     * account, grouped by account, or only one account's when asked.
     */
    /**
     * Every home a site could show, each with what it still needs.
     *
     * Not only the ones already on sale: a client whose homes are still
     * drafts — every new or imported property starts as one — would see an
     * empty list and no reason for it. A home only needing to be published
     * can be, from here; anything else is said, to be finished on the
     * Properties screen.
     */
    public function listingOptions(Request $request): JsonResponse
    {
        $organizationId = $request->string('organization_id')->toString();

        $listings = $this->tenancy->withoutScope(fn (): Collection => Listing::query()
            ->where('status', '!=', ListingStatus::Archived->value)
            ->whereHas('property', fn ($query) => $query->where('status', '!=', PropertyStatus::Archived->value))
            ->with(['organization', 'property.photos', 'photos.propertyPhoto', 'unitType', 'unit'])
            ->when($organizationId !== '', fn ($query) => $query->where('organization_id', $organizationId))
            ->get()
            ->filter(fn (Listing $listing): bool => (bool) $listing->organization?->status->allowsAccess()));

        return response()->json(['data' => $listings
            ->sortBy(fn (Listing $listing): string => $listing->organization?->name.'|'.$listing->displayTitle())
            ->map(fn (Listing $listing): array => $this->option($listing))
            ->values()->all()]);
    }

    /**
     * Put a home on sale so a site can show it: its property activated when
     * that is all it lacks, and the listing published.
     */
    public function publishListing(string $listing): JsonResponse
    {
        $found = $this->tenancy->withoutScope(fn (): ?Listing => Listing::query()->with('organization')->find($listing));

        abort_if($found === null || $found->organization === null || ! $found->organization->status->allowsAccess(), 404);

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

        return response()->json(['data' => $this->option($published->load('organization'))]);
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

    // ------------------------------------------------------------------

    /**
     * @return list<mixed>
     */
    private function slugRules(?Website $ignore = null): array
    {
        return [
            'required', 'string', 'max:60',
            'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            Rule::notIn(Website::RESERVED_SLUGS),
            Rule::unique('websites', 'slug')->ignore($ignore?->getKey()),
        ];
    }

    private function respond(Website $website, int $status = 200): JsonResponse
    {
        $website = $this->tenancy->withoutScope(fn (): Website => $this->withDetails(collect([$website]))->first());

        return (new WebsiteResource($website))->response()->setStatusCode($status);
    }

    /**
     * @param  Collection<int, Website>  $sites
     * @return Collection<int, Website>
     */
    private function withDetails(Collection $sites): Collection
    {
        return $sites->each(fn (Website $site) => $site->load([
            'organization',
            'listings.organization',
            'listings.listing.property',
        ]));
    }
}
