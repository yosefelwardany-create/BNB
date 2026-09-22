<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reviews\Models\Review;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reviews in, responses out.
 *
 * Importing is idempotent on the channel's own identifier, because channels
 * redeliver and a review that appeared twice would halve an average rating for
 * no reason.
 *
 * The review body is never edited here. It is what the guest said; the public
 * copy on the channel is the authority, and a local edit would only make the
 * two disagree while looking authoritative. Only the response is the
 * operator's.
 */
class ReviewService
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Record a review that arrived from a channel.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function import(array $attributes): Review
    {
        $organization = $this->tenancy->organizationOrFail();

        return DB::transaction(function () use ($attributes, $organization): Review {
            $existing = isset($attributes['external_id'])
                ? Review::query()
                    ->where('source', $attributes['source'] ?? 'direct')
                    ->where('external_id', $attributes['external_id'])
                    ->first()
                : null;

            // Already here. A channel redelivering a review must not halve the
            // property's average by counting it twice.
            if ($existing !== null) {
                return $this->refresh($existing, $attributes);
            }

            $review = new Review;
            $review->fill($attributes);
            $review->organization_id = $organization->getKey();
            $review->submitted_at ??= now();

            $this->attachStay($review, $attributes['reservation'] ?? null);

            $review->save();

            return $review;
        });
    }

    /**
     * Reply to a guest.
     *
     * The response is ours to write and ours to correct; the review is not.
     * Rewriting an existing response is allowed — a hasty reply is better
     * fixed than left — and the audit log keeps both.
     */
    public function respond(Review $review, string $response): Review
    {
        $previous = $review->response;

        $review->forceFill([
            'response' => $response,
            'responded_at' => now(),
            'responded_by_id' => auth()->id(),
            'status' => $review->status === Review::HIDDEN
                ? Review::HIDDEN
                : Review::RESPONDED,
        ])->save();

        $this->audit->record(
            action: $previous === null ? 'review.responded' : 'review.response_edited',
            subject: $review,
            description: sprintf('Responded to a review of %s.', $review->property?->name ?? 'a property'),
            context: array_filter(['previous_response' => $previous]),
        );

        return $review->fresh();
    }

    /**
     * Suppress a review in our own interface.
     *
     * Deliberately named for what it does. It changes nothing on the channel,
     * where the review remains public, and pretending otherwise would let an
     * operator believe they had removed something they had not.
     */
    public function hide(Review $review, string $reason): Review
    {
        $review->forceFill([
            'status' => Review::HIDDEN,
            'metadata' => array_merge($review->metadata ?? [], [
                'hidden_reason' => $reason,
                'hidden_by' => auth()->id(),
                'hidden_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $this->audit->record(
            action: 'review.hidden',
            subject: $review,
            description: 'Hidden from internal views. The review is unchanged on the channel.',
            context: ['reason' => $reason],
        );

        return $review->fresh();
    }

    public function unhide(Review $review): Review
    {
        $review->forceFill([
            'status' => $review->hasResponse() ? Review::RESPONDED : Review::PUBLISHED,
        ])->save();

        return $review->fresh();
    }

    /**
     * Rating summary for a property or the whole portfolio.
     *
     * Averaged on the normalised percentage, never on the raw score: a 9 from
     * a channel that rates out of 10 and a 9 from one that rates out of 5 are
     * not the same review, and averaging them raw produces a number that means
     * nothing.
     *
     * @return array<string, mixed>
     */
    public function summary(?string $propertyId = null, ?CarbonImmutable $since = null): array
    {
        $query = Review::query()->fromGuests()->visible();

        if ($propertyId !== null) {
            $query->where('property_id', $propertyId);
        }

        if ($since !== null) {
            $query->where('submitted_at', '>=', $since);
        }

        $row = (clone $query)
            ->selectRaw(
                'count(*) as total, '
                .'avg(rating_percent) as average_percent, '
                .'count(*) filter (where rating_percent < 70) as negative, '
                .'count(*) filter (where response is not null) as responded'
            )
            ->first();

        $total = (int) ($row->total ?? 0);
        $responded = (int) ($row->responded ?? 0);

        return [
            'reviews' => $total,
            // Reported on a 0-100 scale because that is the only scale the
            // sources have in common. A five-star equivalent is offered
            // alongside for display, derived rather than stored.
            'average_percent' => $row->average_percent === null
                ? null
                : round((float) $row->average_percent, 1),
            'average_out_of_five' => $row->average_percent === null
                ? null
                : round((float) $row->average_percent / 20, 2),
            'negative' => (int) ($row->negative ?? 0),
            'responded' => $responded,
            'response_rate' => $total > 0 ? round($responded / $total * 100, 1) : 0.0,
            'awaiting_response' => (clone $query)->whereNull('response')->count(),
        ];
    }

    /**
     * Update a review we already hold from a fresh copy.
     *
     * Channels do amend reviews — a guest edits theirs, a moderator removes a
     * line — so the imported copy is refreshed rather than ignored. The
     * response is never touched, because it is not theirs.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function refresh(Review $review, array $attributes): Review
    {
        $review->fill(collect($attributes)
            ->only([
                'rating', 'rating_scale', 'category_ratings', 'title',
                'public_comment', 'private_comment', 'external_url', 'submitted_at',
            ])
            ->all());

        $review->save();

        return $review->fresh();
    }

    private function attachStay(Review $review, ?Reservation $reservation): void
    {
        if ($reservation === null) {
            return;
        }

        $review->reservation_id = $reservation->getKey();
        $review->property_id ??= $reservation->property_id;
        $review->listing_id ??= $reservation->listing_id;
        $review->guest_id ??= $reservation->guest_id;
        $review->stay_date ??= $reservation->check_out_date;
    }
}
