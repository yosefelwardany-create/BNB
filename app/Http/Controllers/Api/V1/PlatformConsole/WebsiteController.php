<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Listings\Models\Listing;
use App\Domain\Websites\Models\Website;
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
    public function __construct(private readonly TenantContext $tenancy) {}

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
            'settings.heading' => ['nullable', 'string', 'max:120'],
            'settings.tagline' => ['nullable', 'string', 'max:240'],
            'settings.primary_colour' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.accent_colour' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
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
        abort_if($website->listings()->doesntExist(), 422, 'Add at least one property before publishing.');

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
    public function listingOptions(Request $request): JsonResponse
    {
        $organizationId = $request->string('organization_id')->toString();

        $listings = $this->tenancy->withoutScope(fn (): Collection => Listing::query()
            ->bookable()
            ->with(['organization', 'property'])
            ->when($organizationId !== '', fn ($query) => $query->where('organization_id', $organizationId))
            ->get()
            ->filter(fn (Listing $listing): bool => (bool) $listing->organization?->status->allowsAccess()));

        return response()->json(['data' => $listings
            ->sortBy(fn (Listing $listing): string => $listing->organization?->name.'|'.$listing->displayTitle())
            ->map(fn (Listing $listing): array => [
                'listing_id' => $listing->getKey(),
                'title' => $listing->displayTitle(),
                'organization_id' => $listing->organization_id,
                'organization_name' => $listing->organization?->name,
                'city' => $listing->property?->city,
            ])->values()->all()]);
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
