<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Properties;

use App\Domain\Properties\Models\Amenity;
use App\Http\Controllers\Controller;
use App\Http\Resources\AmenityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * The amenity catalogue available to this organization: the shared platform
 * list plus anything the organization has added itself.
 */
class AmenityController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Amenity::query()
            ->availableTo($this->organization()->getKey())
            ->orderBy('category')
            ->orderBy('position');

        if ($category = $request->string('category')->toString()) {
            $query->whereIn('category', explode(',', $category));
        }

        return AmenityResource::collection($query->get());
    }

    /**
     * Add an organization-specific amenity.
     *
     * These do not travel to channels, because a channel has no key to map
     * them onto; the response says so rather than leaving it to be discovered.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('properties.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', 'max:48'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:48'],
        ]);

        $amenity = Amenity::query()->create([
            'organization_id' => $this->organization()->getKey(),
            'key' => Str::slug($data['name'], '_'),
            'name' => $data['name'],
            'category' => $data['category'],
            'icon' => $data['icon'] ?? null,
        ]);

        return response()->json([
            'data' => (new AmenityResource($amenity))->resolve(),
            'notice' => 'Custom amenities are shown on your own booking pages but are not published to channels, which only accept catalogue amenities.',
        ], 201);
    }
}
