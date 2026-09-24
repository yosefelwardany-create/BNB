<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Platform\Models\PlatformAnnouncement;
use App\Http\Controllers\Controller;
use App\Http\Resources\Platform\PlatformAnnouncementResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Notices shown inside every affected tenant's interface.
 */
class PlatformAnnouncementController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PlatformAnnouncement::query();

        if ($request->boolean('live_only')) {
            $query->live();
        }

        return PlatformAnnouncementResource::collection(
            $query->orderByDesc('created_at')->paginate($this->perPage()),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $announcement = new PlatformAnnouncement;
        $announcement->fill($data);
        $announcement->created_by_id = $this->currentUser()->getKey();
        $announcement->save();

        return (new PlatformAnnouncementResource($announcement))
            ->response()
            ->setStatusCode(201);
    }

    public function show(PlatformAnnouncement $announcement): PlatformAnnouncementResource
    {
        return new PlatformAnnouncementResource($announcement);
    }

    public function update(Request $request, PlatformAnnouncement $announcement): PlatformAnnouncementResource
    {
        $announcement->fill($request->validate($this->rules()))->save();

        return new PlatformAnnouncementResource($announcement->fresh());
    }

    /**
     * Withdraw a notice.
     *
     * Deleted rather than archived, and this is the one place in the product
     * where that is right: an announcement is a message, not a record of
     * anything that happened, and a withdrawn one has no history worth keeping.
     */
    public function destroy(PlatformAnnouncement $announcement): JsonResponse
    {
        $announcement->delete();

        return response()->json(['message' => 'The announcement has been withdrawn.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'body' => ['sometimes', 'required', 'string', 'max:5000'],
            'level' => ['sometimes', Rule::in([
                PlatformAnnouncement::LEVEL_INFO,
                PlatformAnnouncement::LEVEL_WARNING,
                PlatformAnnouncement::LEVEL_CRITICAL,
            ])],
            'audience' => ['sometimes', Rule::in([
                PlatformAnnouncement::AUDIENCE_ALL,
                PlatformAnnouncement::AUDIENCE_TRIAL,
                PlatformAnnouncement::AUDIENCE_ACTIVE,
                PlatformAnnouncement::AUDIENCE_PAST_DUE,
                PlatformAnnouncement::AUDIENCE_SPECIFIC,
            ])],
            'organization_ids' => ['sometimes', 'nullable', 'array'],
            'organization_ids.*' => ['string', 'exists:organizations,id'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'is_published' => ['sometimes', 'boolean'],
            'is_dismissible' => ['sometimes', 'boolean'],
        ];
    }
}
