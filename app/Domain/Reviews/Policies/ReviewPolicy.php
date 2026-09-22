<?php

declare(strict_types=1);

namespace App\Domain\Reviews\Policies;

use App\Domain\Reviews\Models\Review;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Reviews.
 *
 * Reading is wide: anybody handling guests needs to know what previous guests
 * said about a property, and a reservations agent taking a booking for a flat
 * with a run of complaints about the heating should be able to see them.
 *
 * Replying is narrow, because a response is published under the business's
 * name and cannot be taken back.
 *
 * Nothing edits the review itself, at any permission level. It is what the
 * guest said.
 */
class ReviewPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsAny($user, ['reviews.view', 'reservations.view']);
    }

    public function view(User $user, Review $review): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Replying in public, under the business's name.
     */
    public function respond(User $user, Review $review): bool
    {
        return $this->access->allows($user, 'reviews.respond');
    }

    /**
     * Suppressing a review in our own interface. It stays public on the
     * channel, which is why this is not a deletion and is not named like one.
     */
    public function hide(User $user, Review $review): bool
    {
        return $this->access->allows($user, 'reviews.respond');
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'reviews.respond');
    }

    /**
     * Never. A review is what a guest said, and the copy on the channel is the
     * authority; editing ours would only make the two disagree.
     */
    public function update(User $user, Review $review): bool
    {
        return false;
    }

    public function delete(User $user, Review $review): bool
    {
        return false;
    }
}
