<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Experience;

use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Services\ReviewService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Reviews, and what we said back.
 *
 * There is no update endpoint, and that is the point of this controller. A
 * review is what a guest said; the public copy on the channel is the
 * authority, and editing ours would only make the two disagree while looking
 * official. The response is the only part that belongs to the operator.
 *
 * `hide` is named for what it does. It suppresses a review here and changes
 * nothing on the channel, where it remains public — calling it "delete" would
 * let an operator believe they had removed something they had not.
 */
class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Review::class);

        $query = Review::query()->with(['property', 'guest']);

        if ($request->filled('property_id')) {
            $query->where('property_id', $request->string('property_id')->toString());
        }

        if ($request->filled('reservation_id')) {
            $query->where('reservation_id', $request->string('reservation_id')->toString());
        }

        if ($request->filled('source')) {
            $query->whereIn('source', (array) $request->input('source'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->string('direction')->toString());
        }

        // The operational queue: a poor review answered within a day reads
        // very differently from the same review answered in a month.
        if ($request->boolean('awaiting_response')) {
            $query->awaitingResponse();
        }

        if ($request->boolean('negative_only')) {
            $query->negative();
        }

        if (! $request->boolean('include_hidden')) {
            $query->visible();
        }

        return ReviewResource::collection(
            $query->orderByDesc('submitted_at')->paginate($this->perPage()),
        );
    }

    public function show(Review $review): ReviewResource
    {
        $this->authorize('view', $review);

        return new ReviewResource($review->load(['property', 'guest', 'reservation', 'responder']));
    }

    /**
     * Ratings and response rate.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Review::class);

        $data = $request->validate([
            'property_id' => ['sometimes', 'string', 'size:26'],
            'since' => ['sometimes', 'date'],
        ]);

        return response()->json([
            'data' => $this->reviews->summary(
                $data['property_id'] ?? null,
                isset($data['since']) ? CarbonImmutable::parse($data['since']) : null,
            ),
            'meta' => [
                // Said explicitly, because an average across channels that
                // rate out of 5 and out of 10 is meaningless unless everybody
                // knows it was normalised first.
                'note' => 'Averages are computed on a normalised 0-100 scale, because channels rate on different scales.',
            ],
        ]);
    }

    /**
     * Record a review taken by hand — a direct guest who emailed one in.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Review::class);

        $data = $request->validate([
            'property_id' => ['required', 'string', 'size:26'],
            'reservation_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'guest_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'direction' => ['sometimes', 'in:guest_to_host,host_to_guest'],
            'rating' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rating_scale' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'public_comment' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'private_comment' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'category_ratings' => ['sometimes', 'nullable', 'array'],
            'submitted_at' => ['sometimes', 'date'],
            'stay_date' => ['sometimes', 'nullable', 'date'],
        ]);

        $review = $this->reviews->import($data + ['source' => 'direct']);

        return (new ReviewResource($review))->response()->setStatusCode(201);
    }

    /**
     * Reply to a guest.
     */
    public function respond(Request $request, Review $review): ReviewResource
    {
        $this->authorize('respond', $review);

        $data = $request->validate([
            'response' => ['required', 'string', 'max:5000'],
        ]);

        return new ReviewResource($this->reviews->respond($review, $data['response']));
    }

    /**
     * Suppress a review in our own views.
     */
    public function hide(Request $request, Review $review): ReviewResource
    {
        $this->authorize('hide', $review);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return new ReviewResource($this->reviews->hide($review, $data['reason']));
    }

    public function unhide(Review $review): ReviewResource
    {
        $this->authorize('hide', $review);

        return new ReviewResource($this->reviews->unhide($review));
    }
}
