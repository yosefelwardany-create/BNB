<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\People;

use App\Domain\Guests\Models\Guest;
use App\Domain\Guests\Services\GuestDirectory;
use App\Http\Controllers\Controller;
use App\Http\Resources\GuestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GuestController extends Controller
{
    public function __construct(private readonly GuestDirectory $directory) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Guest::class);

        $query = Guest::query()
            // Merged-away profiles are hidden: they exist only so old
            // references still resolve.
            ->active()
            ->search($request->string('search')->toString() ?: null);

        if ($request->boolean('returning_only')) {
            $query->returning();
        }

        if ($request->boolean('marketing_consent')) {
            $query->withMarketingConsent();
        }

        if ($request->filled('tags')) {
            $query->taggedWithAny(explode(',', $request->string('tags')->toString()));
        }

        $sort = $request->string('sort', 'display_name')->toString();
        $direction = $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc';

        if (in_array($sort, ['display_name', 'created_at', 'last_stay_date', 'lifetime_value', 'reservations_count'], true)) {
            $query->orderBy($sort, $direction);
        }

        return GuestResource::collection($query->paginate($this->perPage()));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Guest::class);

        $data = $request->validate($this->rules());

        // Creating a guest who already exists would fragment their history, so
        // a match is reused and enriched instead.
        $existing = $this->directory->findMatch($data);

        if ($existing !== null) {
            return response()->json([
                'data' => (new GuestResource($this->directory->enrich($existing, $data)))->resolve(),
                'notice' => 'An existing guest with these details was found and updated rather than duplicated.',
                'matched_existing' => true,
            ]);
        }

        $guest = $this->directory->findOrCreate($data);

        return (new GuestResource($guest))->response()->setStatusCode(201);
    }

    public function show(Guest $guest): GuestResource
    {
        $this->authorize('view', $guest);

        return new GuestResource($guest->load(['tags', 'reservations.property']));
    }

    public function update(Request $request, Guest $guest): GuestResource
    {
        $this->authorize('update', $guest);

        $data = $request->validate($this->rules($guest));

        $tags = $data['tags'] ?? null;
        unset($data['tags']);

        // Consent is recorded with when it was given, because "we have
        // consent" is not a defensible answer on its own.
        if (array_key_exists('marketing_consent', $data)
            && (bool) $data['marketing_consent'] !== (bool) $guest->marketing_consent) {
            $guest->forceFill([
                'marketing_consent_at' => $data['marketing_consent'] ? now() : null,
                'marketing_consent_source' => $data['marketing_consent'] ? 'admin' : null,
            ]);
        }

        $guest->fill($data)->save();

        if ($tags !== null) {
            $guest->syncTags($tags);
        }

        return new GuestResource($guest->fresh(['tags']));
    }

    /**
     * Profiles that look like duplicates of this one.
     */
    public function duplicates(Guest $guest): JsonResponse
    {
        $this->authorize('view', $guest);

        return response()->json([
            'data' => GuestResource::collection($this->directory->findDuplicates($guest))->resolve(),
        ]);
    }

    /**
     * Merge a duplicate into this profile.
     *
     * The losing profile is kept and marked as merged rather than deleted, so
     * external references to its identifier still resolve.
     */
    public function merge(Request $request, Guest $guest): JsonResponse
    {
        $this->authorize('merge', $guest);

        $data = $request->validate([
            'duplicate_id' => ['required', 'string', 'exists:guests,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $duplicate = Guest::query()->findOrFail($data['duplicate_id']);

        $merged = $this->directory->merge($guest, $duplicate, $data['reason'] ?? null);

        return response()->json([
            'data' => (new GuestResource($merged))->resolve(),
            'message' => 'Profiles merged. The duplicate has been kept as a redirect so older references still work.',
        ]);
    }

    /**
     * Every duplicate cluster in the organization, for a review screen.
     */
    public function duplicateClusters(): JsonResponse
    {
        $this->authorize('viewAny', Guest::class);

        return response()->json(['data' => $this->directory->duplicateClusters()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Guest $guest = null): array
    {
        return [
            'first_name' => [$guest === null ? 'required' : 'sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'language' => ['sometimes', 'nullable', 'string', 'max:12'],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone'],
            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'company' => ['sometimes', 'nullable', 'string', 'max:160'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'marketing_consent' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:60'],
        ];
    }
}
