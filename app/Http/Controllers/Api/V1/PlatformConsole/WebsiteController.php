<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Websites\Content\SiteContent;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Services\WebsiteAdministration;
use App\Domain\Websites\Services\WebsiteReadiness;
use App\Domain\Websites\Support\WebsiteMedia;
use App\Http\Controllers\Controller;
use App\Http\Resources\WebsiteResource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

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
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly WebsiteReadiness $readiness,
        private readonly WebsiteAdministration $sites,
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

        $website->forceFill(array_intersect_key($data, array_flip(['slug', 'hold_minutes', 'request_hours'])));
        $this->sites->updateDetails($website, $data);

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

        $this->sites->replaceListings($website, $data['listings']);

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

        return response()->json(['data' => $this->sites->listingOptions($organizationId === '' ? null : $organizationId)]);
    }

    /**
     * Put a home on sale so a site can show it: its property activated when
     * that is all it lacks, and the listing published.
     */
    public function publishListing(string $listing): JsonResponse
    {
        $published = $this->sites->publishListing($listing);

        abort_if($published === null, 404);

        return response()->json(['data' => $published]);
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
        return (new WebsiteResource($this->sites->withDetails($website)))->response()->setStatusCode($status);
    }

    /**
     * @param  Collection<int, Website>  $sites
     * @return Collection<int, Website>
     */
    private function withDetails(Collection $sites): Collection
    {
        return $sites->each(fn (Website $site) => $this->sites->withDetails($site));
    }
}
