<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Reviews\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'source' => $this->source,
            'external_url' => $this->external_url,

            // The raw score with its own scale, plus the normalised
            // percentage. Both, because 9 out of 10 and 9 out of 5 are not the
            // same review and a single number cannot say which this is.
            'rating' => $this->rating,
            'rating_scale' => (int) $this->rating_scale,
            'rating_percent' => $this->rating_percent,
            'category_ratings' => $this->category_ratings,
            'is_negative' => $this->isNegative(),

            'title' => $this->title,
            'public_comment' => $this->public_comment,
            'private_comment' => $this->private_comment,

            'response' => $this->response,
            'has_response' => $this->hasResponse(),
            'responded_at' => $this->responded_at?->toIso8601String(),
            'response_hours' => $this->responseHours(),

            'status' => $this->status,
            // Suppressed here, still public there. Reported as its own field
            // so an interface can say so rather than implying it was removed.
            'is_hidden_internally' => $this->status === Review::HIDDEN,

            'property_id' => $this->property_id,
            'reservation_id' => $this->reservation_id,
            'guest_id' => $this->guest_id,
            'property' => new PropertyResource($this->whenLoaded('property')),
            'guest' => new GuestResource($this->whenLoaded('guest')),

            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'stay_date' => $this->stay_date?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
