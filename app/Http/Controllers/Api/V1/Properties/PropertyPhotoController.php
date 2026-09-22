<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Properties;

use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Http\Controllers\Controller;
use App\Http\Resources\PropertyPhotoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Property photography.
 *
 * Uploads are validated by actual image content rather than by the filename or
 * the client-supplied content type, because a file extension is not evidence
 * of anything. Files are stored under a tenant-prefixed key on a private disk;
 * nothing is written into a publicly served directory.
 */
class PropertyPhotoController extends Controller
{
    public function index(Property $property): AnonymousResourceCollection
    {
        $this->authorize('view', $property);

        return PropertyPhotoResource::collection($property->photos()->get());
    }

    public function store(Request $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $request->validate([
            'photos' => ['required', 'array', 'max:30'],
            // `image` checks the file really decodes as an image; `dimensions`
            // rejects the tiny or absurd files that indicate a bad upload.
            'photos.*' => [
                'file', 'image', 'mimes:jpeg,png,webp', 'max:15360',
                'dimensions:min_width=200,min_height=200,max_width=12000,max_height=12000',
            ],
            'unit_id' => ['sometimes', 'nullable', 'string', 'exists:units,id'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:255'],
            'room_type' => ['sometimes', 'nullable', 'string', 'max:48'],
        ]);

        $disk = config('filesystems.default');
        $position = (int) $property->photos()->max('position');
        $created = [];

        foreach ($request->file('photos') as $file) {
            $path = $file->store(
                sprintf('organizations/%s/properties/%s', $property->organization_id, $property->getKey()),
                $disk,
            );

            $dimensions = @getimagesize($file->getRealPath()) ?: [null, null];

            $created[] = PropertyPhoto::query()->create([
                'organization_id' => $property->organization_id,
                'property_id' => $property->getKey(),
                'unit_id' => $request->input('unit_id'),
                'disk' => $disk,
                'path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'caption' => $request->input('caption'),
                'room_type' => $request->input('room_type'),
                'position' => ++$position,
                // The first photo a property ever gets becomes its cover.
                'is_cover' => ! $property->photos()->where('is_cover', true)->exists() && $position === 1,
            ]);
        }

        return response()->json([
            'data' => PropertyPhotoResource::collection($created)->resolve(),
        ], 201);
    }

    public function update(Request $request, PropertyPhoto $photo): PropertyPhotoResource
    {
        $this->authorize('update', $photo->property);

        $data = $request->validate([
            'caption' => ['sometimes', 'nullable', 'string', 'max:255'],
            'room_type' => ['sometimes', 'nullable', 'string', 'max:48'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'is_cover' => ['sometimes', 'boolean'],
            'unit_id' => ['sometimes', 'nullable', 'string', 'exists:units,id'],
        ]);

        DB::transaction(function () use ($photo, $data): void {
            // A property has exactly one cover photo.
            if (($data['is_cover'] ?? false) === true) {
                PropertyPhoto::query()
                    ->where('property_id', $photo->property_id)
                    ->whereKeyNot($photo->getKey())
                    ->update(['is_cover' => false]);
            }

            $photo->update($data);
        });

        return new PropertyPhotoResource($photo->refresh());
    }

    /**
     * Reorder a property's photos in one call, which is what a drag-and-drop
     * gallery needs.
     */
    public function reorder(Request $request, Property $property): AnonymousResourceCollection
    {
        $this->authorize('update', $property);

        $data = $request->validate([
            'photo_ids' => ['required', 'array'],
            'photo_ids.*' => ['string'],
        ]);

        DB::transaction(function () use ($property, $data): void {
            foreach (array_values($data['photo_ids']) as $position => $id) {
                PropertyPhoto::query()
                    ->where('property_id', $property->getKey())
                    ->whereKey($id)
                    ->update(['position' => $position]);
            }
        });

        return PropertyPhotoResource::collection($property->photos()->get());
    }

    public function destroy(PropertyPhoto $photo): JsonResponse
    {
        $this->authorize('update', $photo->property);

        $wasCover = $photo->is_cover;
        $propertyId = $photo->property_id;

        DB::transaction(function () use ($photo, $wasCover, $propertyId): void {
            Storage::disk($photo->disk)->delete($photo->path);
            $photo->delete();

            // Promote the next photo so the property is never left without a
            // cover image, which would break every listing that shows one.
            if ($wasCover) {
                PropertyPhoto::query()
                    ->where('property_id', $propertyId)
                    ->orderBy('position')
                    ->limit(1)
                    ->update(['is_cover' => true]);
            }
        });

        return response()->json(['message' => 'Photo removed.']);
    }

    /**
     * Stream a photo stored on a private disk.
     *
     * Used where the disk cannot produce signed URLs (local development). The
     * authorization check runs first, so a private disk stays private.
     */
    public function show(PropertyPhoto $photo): StreamedResponse
    {
        $this->authorize('view', $photo->property);

        abort_unless(Storage::disk($photo->disk)->exists($photo->path), 404);

        return Storage::disk($photo->disk)->response(
            $photo->path,
            $photo->original_filename,
            ['Content-Type' => $photo->mime_type ?? 'application/octet-stream'],
        );
    }
}
