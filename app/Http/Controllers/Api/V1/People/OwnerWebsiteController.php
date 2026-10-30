<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\People;

use App\Domain\Organization\Models\Organization;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Services\WebsiteAdministration;
use App\Domain\Websites\Support\WebsiteMedia;
use App\Http\Controllers\Controller;
use App\Http\Resources\OwnerWebsiteResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A client's own sites, from the client portal: their details, their logo and
 * the homes they show. The pages themselves go through the same editor as the
 * platform's (WebsiteEditorController). Every route is behind EnsureOwnWebsite:
 * the account's own client, and their own site.
 *
 * The address, the booking rules, whether the site is online and whose it is
 * are not taken here; they stay with the platform. A client's logo is never an
 * SVG, and their social links are web addresses only.
 */
class OwnerWebsiteController extends Controller
{
    public function __construct(private readonly WebsiteAdministration $sites) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $sites = Website::query()
            ->where('kind', Website::KIND_CLIENT)
            ->where('organization_id', $this->account($request)->getKey())
            ->orderBy('name')
            ->get()
            ->each(fn (Website $site) => $this->sites->withDetails($site));

        return OwnerWebsiteResource::collection($sites);
    }

    public function show(Website $website): JsonResponse
    {
        return $this->respond($website);
    }

    public function update(Request $request, Website $website): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'settings' => ['sometimes', 'array'],
            'settings.contact_email' => ['nullable', 'email', 'max:190'],
            'settings.contact_phone' => ['nullable', 'string', 'max:40'],
            'settings.address_line' => ['nullable', 'string', 'max:240'],
            'settings.instagram_url' => ['nullable', 'url:https', 'max:240'],
            'settings.facebook_url' => ['nullable', 'url:https', 'max:240'],
        ], [
            'settings.instagram_url.url' => 'Use a link starting with https://.',
            'settings.facebook_url.url' => 'Use a link starting with https://.',
        ]);

        return $this->respond($this->sites->updateDetails($website, $data));
    }

    public function media(Request $request, Website $website): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:logo'],
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        WebsiteMedia::store($website, $data['file'], $data['kind']);

        return $this->respond($website->fresh());
    }

    /**
     * The account's own homes, each with what it still needs. Putting one on
     * sale is the management company's to do, so none offers it here.
     */
    public function listingOptions(Website $website): JsonResponse
    {
        return response()->json(['data' => array_map(
            fn (array $option): array => array_replace($option, ['can_publish' => false]),
            $this->sites->listingOptions((string) $website->organization_id),
        )]);
    }

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

    private function respond(Website $website): JsonResponse
    {
        return (new OwnerWebsiteResource($this->sites->withDetails($website)))->response();
    }

    private function account(Request $request): Organization
    {
        $organization = $request->attributes->get('organization');
        abort_unless($organization instanceof Organization, 404);

        return $organization;
    }
}
