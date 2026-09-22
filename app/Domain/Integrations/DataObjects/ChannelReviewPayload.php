<?php

declare(strict_types=1);

namespace App\Domain\Integrations\DataObjects;

/**
 * A review as published on a channel.
 *
 * `categoryRatings` uses the platform's own category names; adapters map the
 * channel's vocabulary onto them so reports compare like with like.
 */
final class ChannelReviewPayload
{
    /**
     * @param  array<string, float>  $categoryRatings
     */
    public function __construct(
        public readonly string $externalReviewId,
        public readonly ?string $externalReservationId,
        public readonly ?string $externalListingId,
        public readonly float $rating,
        public readonly float $ratingScale = 5.0,
        public readonly ?string $publicComment = null,
        public readonly ?string $privateComment = null,
        public readonly ?string $reviewerName = null,
        public readonly array $categoryRatings = [],
        public readonly ?\DateTimeImmutable $submittedAt = null,
        public readonly ?string $response = null,
    ) {}
}
