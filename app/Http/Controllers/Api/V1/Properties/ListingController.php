<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Properties;

use App\Domain\Listings\Models\Listing;
use App\Domain\Listings\Models\ListingVersion;
use App\Domain\Listings\Services\ListingService;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Services\AccessControl;
use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ListingController extends Controller
{
    public function __construct(
        private readonly ListingService $listings,
        private readonly AccessControl $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Listing::class);

        $restricted = $this->access->restrictedPropertyIds($this->currentUser());

        $query = Listing::query()
            ->with(['property'])
            ->when($restricted !== null, fn ($q) => $q->whereIn('property_id', $restricted));

        if ($status = $request->string('status')->toString()) {
            $query->whereIn('status', explode(',', $status));
        } else {
            $query->where('status', '!=', 'archived');
        }

        if ($propertyId = $request->string('property_id')->toString()) {
            $query->whereIn('property_id', explode(',', $propertyId));
        }

        if ($request->boolean('bookable_only')) {
            $query->bookable();
        }

        return ListingResource::collection(
            $query->orderBy('name')->paginate($this->perPage())
        );
    }

    public function store(Request $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);
        $this->authorize('create', Listing::class);

        $data = $request->validate($this->rules($property));
        unset($data['change_reason']);

        $listing = $this->listings->create($property, $data);

        return (new ListingResource($listing->load('property')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Listing $listing): ListingResource
    {
        $this->authorize('view', $listing);

        return new ListingResource(
            $listing->load(['property.photos', 'property.amenities', 'photos.propertyPhoto', 'amenityOverrides', 'channelListings'])
        );
    }

    public function update(Request $request, Listing $listing): ListingResource
    {
        $this->authorize('update', $listing);

        $data = $request->validate($this->rules($listing->property, $listing));

        // The reason is metadata about the change, not part of the listing.
        $reason = $data['change_reason'] ?? null;
        unset($data['change_reason']);

        return new ListingResource(
            $this->listings->update($listing, $data, $reason)->load('property')
        );
    }

    /**
     * Put the listing on sale.
     */
    public function publish(Listing $listing): ListingResource
    {
        $this->authorize('publish', $listing);

        $listing->load(['property.photos', 'photos.propertyPhoto', 'unitType', 'unit']);

        return new ListingResource($this->listings->publish($listing)->load('property'));
    }

    public function pause(Request $request, Listing $listing): ListingResource
    {
        $this->authorize('publish', $listing);

        return new ListingResource(
            $this->listings->pause($listing, $request->string('reason')->toString() ?: null)
                ->load('property')
        );
    }

    public function destroy(Request $request, Listing $listing): JsonResponse
    {
        $this->authorize('delete', $listing);

        $this->listings->archive($listing, $request->string('reason')->toString() ?: null);

        return response()->json(['message' => 'Listing archived.']);
    }

    /**
     * What stands between the listing and being published.
     */
    public function readiness(Listing $listing): JsonResponse
    {
        $this->authorize('view', $listing);

        $listing->load(['property.photos', 'photos.propertyPhoto', 'unitType', 'unit']);

        $blockers = $this->listings->publicationBlockers($listing);

        return response()->json([
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'status' => $listing->status->value,
        ]);
    }

    /**
     * The listing's content history.
     */
    public function versions(Listing $listing): JsonResponse
    {
        $this->authorize('view', $listing);

        return response()->json([
            'data' => $listing->versions()->with('createdBy')->limit(50)->get()
                ->map(fn (ListingVersion $version): array => [
                    'id' => $version->getKey(),
                    'version' => $version->version,
                    'changed_fields' => $version->changed_fields,
                    'reason' => $version->reason,
                    'created_by' => $version->createdBy?->fullName(),
                    'created_at' => $version->created_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    public function restoreVersion(Listing $listing, ListingVersion $version): ListingResource
    {
        $this->authorize('update', $listing);

        return new ListingResource(
            $this->listings->restoreVersion($listing, $version)->load('property')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Property $property, ?Listing $listing = null): array
    {
        return [
            'name' => [$listing === null ? 'required' : 'sometimes', 'string', 'max:160'],
            'unit_type_id' => ['sometimes', 'nullable', 'string', Rule::exists('unit_types', 'id')
                ->where('property_id', $property->getKey())],
            'unit_id' => ['sometimes', 'nullable', 'string', Rule::exists('units', 'id')
                ->where('property_id', $property->getKey())],
            'is_primary' => ['sometimes', 'boolean'],

            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'space_description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'neighbourhood_description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'transit_description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'house_rules' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'check_in_instructions' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'check_out_instructions' => ['sometimes', 'nullable', 'string', 'max:20000'],

            'max_occupancy' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200'],
            'bedrooms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'bathrooms' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'beds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:200'],

            'base_rate' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'cleaning_fee' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'extra_guest_fee' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'extra_guest_after' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'minimum_nights' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'maximum_nights' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'advance_notice_hours' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:8760'],
            'booking_window_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1825'],

            'cancellation_policy_id' => ['sometimes', 'nullable', 'string', 'exists:cancellation_policies,id'],
            'check_in_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'check_out_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'instant_book' => ['sometimes', 'nullable', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'change_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
