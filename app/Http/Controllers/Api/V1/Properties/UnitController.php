<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Properties;

use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\Unit;
use App\Domain\Properties\Services\UnitService;
use App\Http\Controllers\Controller;
use App\Http\Resources\UnitResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function __construct(private readonly UnitService $units) {}

    public function index(Request $request, Property $property): AnonymousResourceCollection
    {
        $this->authorize('view', $property);
        $this->authorize('viewAny', Unit::class);

        $query = $property->units()->with('unitType');

        if ($status = $request->string('status')->toString()) {
            $query->whereIn('status', explode(',', $status));
        }

        if ($request->boolean('sellable_only')) {
            $query->sellable();
        }

        return UnitResource::collection($query->get());
    }

    public function store(Request $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);
        $this->authorize('create', Unit::class);

        $data = $request->validate($this->rules($property));

        $unit = $this->units->create($property, $data);

        return (new UnitResource($unit->load('unitType')))->response()->setStatusCode(201);
    }

    public function show(Unit $unit): UnitResource
    {
        $this->authorize('view', $unit);

        return new UnitResource($unit->load(['unitType', 'property']));
    }

    public function update(Request $request, Unit $unit): UnitResource
    {
        $this->authorize('update', $unit);

        $data = $request->validate($this->rules($unit->property, $unit));

        return new UnitResource($this->units->update($unit, $data)->load('unitType'));
    }

    /**
     * Take a unit off the market, or put it back.
     */
    public function changeStatus(Request $request, Unit $unit): UnitResource
    {
        $this->authorize('update', $unit);

        $data = $request->validate([
            'status' => ['required', Rule::in(['available', 'out_of_service', 'maintenance', 'renovation'])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'until' => ['sometimes', 'nullable', 'date'],
        ]);

        return new UnitResource($this->units->changeStatus(
            $unit,
            $data['status'],
            $data['reason'] ?? null,
            isset($data['until']) ? \Carbon\CarbonImmutable::parse($data['until']) : null,
        ));
    }

    /**
     * Create several units at once from a naming pattern — how a building of
     * forty studios is actually set up.
     */
    public function bulkCreate(Request $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);
        $this->authorize('create', Unit::class);

        $data = $request->validate([
            'unit_type_id' => ['sometimes', 'nullable', 'string', 'exists:unit_types,id'],
            'name_pattern' => ['required', 'string', 'max:64'],
            'start' => ['required', 'integer', 'min:0', 'max:100000'],
            'count' => ['required', 'integer', 'min:1', 'max:500'],
            'floor' => ['sometimes', 'nullable', 'string', 'max:16'],
            'max_occupancy' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'base_rate' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $units = $this->units->createFromPattern($property, $data);

        return response()->json([
            'created' => count($units),
            'units' => UnitResource::collection($units)->resolve(),
        ], 201);
    }

    public function destroy(Unit $unit): JsonResponse
    {
        $this->authorize('delete', $unit);

        $this->units->archive($unit);

        return response()->json([
            'message' => 'Unit archived. Its reservation history has been kept.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Property $property, ?Unit $unit = null): array
    {
        return [
            'name' => [$unit === null ? 'required' : 'sometimes', 'string', 'max:120'],
            'code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'unit_type_id' => ['sometimes', 'nullable', 'string', Rule::exists('unit_types', 'id')
                ->where('property_id', $property->getKey())],
            // A unit's parent must belong to the same property, or the
            // availability grouping would span buildings.
            'parent_unit_id' => ['sometimes', 'nullable', 'string', Rule::exists('units', 'id')
                ->where('property_id', $property->getKey())],
            'floor' => ['sometimes', 'nullable', 'string', 'max:16'],
            'status' => ['sometimes', Rule::in(['available', 'out_of_service', 'maintenance', 'renovation'])],
            'bedrooms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'bathrooms' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'beds' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:200'],
            'max_occupancy' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200'],
            'base_rate' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'access_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_bookable' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
