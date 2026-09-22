<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Properties;

use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Users\Services\AccessControl;
use App\Http\Controllers\Controller;
use App\Http\Requests\Properties\StorePropertyRequest;
use App\Http\Requests\Properties\UpdatePropertyRequest;
use App\Http\Resources\PropertyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PropertyController extends Controller
{
    public function __construct(
        private readonly PropertyService $properties,
        private readonly AccessControl $access,
    ) {}

    /**
     * List properties, filtered and paginated.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Property::class);

        $query = Property::query()
            ->with(['portfolio'])
            ->withCount(['units', 'listings'])
            // A member restricted to certain properties sees only those, in
            // every listing endpoint, without the caller having to remember.
            ->visibleTo($this->access->restrictedPropertyIds($this->currentUser()))
            ->search($request->string('search')->toString() ?: null);

        if ($status = $request->string('status')->toString()) {
            $query->whereIn('status', explode(',', $status));
        } else {
            // Archived properties are excluded unless asked for, so they do
            // not clutter day-to-day work.
            $query->where('status', '!=', 'archived');
        }

        foreach (['portfolio_id', 'complex_id', 'property_type', 'city', 'country_code'] as $filter) {
            if ($value = $request->string($filter)->toString()) {
                $query->whereIn($filter, explode(',', $value));
            }
        }

        if ($request->filled('tags')) {
            $query->taggedWithAny(explode(',', $request->string('tags')->toString()));
        }

        if ($request->boolean('bookable_only')) {
            $query->bookable();
        }

        $sort = $request->string('sort', 'name')->toString();
        $direction = $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc';

        if (in_array($sort, ['name', 'created_at', 'city', 'status', 'base_rate'], true)) {
            $query->orderBy($sort, $direction);
        }

        return PropertyResource::collection($query->paginate($this->perPage()));
    }

    public function store(StorePropertyRequest $request): JsonResponse
    {
        $this->authorize('create', Property::class);

        $property = $this->properties->create(
            $request->propertyAttributes(),
            $request->input('amenity_ids', []),
        );

        if ($request->filled('tags')) {
            $property->syncTags($request->input('tags'));
        }

        if ($request->filled('rooms')) {
            $this->properties->syncRooms($property, $request->input('rooms'));
        }

        return (new PropertyResource($property->fresh(['amenities', 'rooms', 'portfolio'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Property $property): PropertyResource
    {
        $this->authorize('view', $property);

        $property->load([
            'portfolio', 'complex', 'amenities', 'photos', 'rooms',
            'unitTypes', 'units.unitType', 'listings', 'tags',
        ]);
        $property->loadCount(['units', 'listings']);

        return new PropertyResource($property);
    }

    public function update(UpdatePropertyRequest $request, Property $property): PropertyResource
    {
        $this->authorize('update', $property);

        $this->properties->update($property, $request->propertyAttributes());

        if ($request->has('amenity_ids')) {
            $this->properties->syncAmenities($property, $request->input('amenity_ids', []));
        }

        if ($request->has('tags')) {
            $property->syncTags($request->input('tags', []));
        }

        if ($request->has('rooms')) {
            $this->properties->syncRooms($property, $request->input('rooms', []));
        }

        return new PropertyResource($property->fresh(['amenities', 'rooms', 'portfolio', 'tags']));
    }

    /**
     * Put a property on the market.
     */
    public function activate(Property $property): PropertyResource
    {
        $this->authorize('activate', $property);

        return new PropertyResource($this->properties->activate($property));
    }

    public function deactivate(Request $request, Property $property): PropertyResource
    {
        $this->authorize('activate', $property);

        return new PropertyResource(
            $this->properties->deactivate($property, $request->string('reason')->toString() ?: null)
        );
    }

    /**
     * Retire a property.
     *
     * A property that has traded is never deleted: its reservations, ledger
     * entries and owner statements have to stay resolvable, so this archives.
     */
    public function destroy(Request $request, Property $property): JsonResponse
    {
        $this->authorize('delete', $property);

        $this->properties->archive($property, $request->string('reason')->toString() ?: null);

        return response()->json([
            'message' => 'Property archived. Its history and financial records have been kept.',
            'status' => $property->status->value,
        ]);
    }

    /**
     * What still stands between this property and going live.
     */
    public function readiness(Property $property): JsonResponse
    {
        $this->authorize('view', $property);

        $blockers = $this->properties->activationBlockers($property);

        return response()->json([
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'status' => $property->status->value,
        ]);
    }
}
