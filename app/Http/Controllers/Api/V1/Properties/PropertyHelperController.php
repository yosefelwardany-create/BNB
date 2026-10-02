<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Properties;

use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Properties\StorePropertyHelperRequest;
use App\Http\Resources\PropertyHelperResource;
use Illuminate\Http\JsonResponse;

/**
 * The people behind one property.
 *
 * Authorised against the property rather than a permission of its own: knowing
 * who cleans a flat is part of knowing the flat, and a separate permission would
 * produce roles that can see a property but not who looks after it — a
 * distinction no operator has asked for.
 *
 * Changing the list is a property update, because that is what it is.
 */
class PropertyHelperController extends Controller
{
    public function index(Property $property): JsonResponse
    {
        $this->authorize('view', $property);

        return response()->json([
            'data' => PropertyHelperResource::collection(
                // Eager loaded: every row resolves its name and number through
                // one of these, and a list of eight helpers would otherwise be
                // sixteen queries.
                $property->helpers()->with(['vendor', 'user'])->get(),
            ),
            'meta' => ['roles' => PropertyHelper::roles()],
        ]);
    }

    public function store(StorePropertyHelperRequest $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $helper = new PropertyHelper($request->validated());
        $helper->property_id = $property->getKey();
        $helper->save();

        return response()->json(
            ['data' => new PropertyHelperResource($helper->load(['vendor', 'user']))],
            201,
        );
    }

    public function update(StorePropertyHelperRequest $request, PropertyHelper $helper): JsonResponse
    {
        $this->authorize('update', $helper->property);

        $helper->fill($request->validated())->save();

        return response()->json([
            'data' => new PropertyHelperResource($helper->load(['vendor', 'user'])),
        ]);
    }

    /**
     * Take somebody off the list.
     *
     * A real delete, and the one place in this platform where that is right: a
     * helper row is a current arrangement, not a record of something that
     * happened. Nothing financial, operational or historical points at it — the
     * tasks a cleaner did keep their own record — so keeping a retired entry
     * would only make the list somebody reads at midnight longer.
     */
    public function destroy(PropertyHelper $helper): JsonResponse
    {
        $this->authorize('update', $helper->property);

        $helper->delete();

        return response()->json(null, 204);
    }
}
