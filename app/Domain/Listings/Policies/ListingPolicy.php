<?php

declare(strict_types=1);

namespace App\Domain\Listings\Policies;

use App\Domain\Listings\Models\Listing;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Listing access follows the property's restriction set, and publishing is a
 * separate permission from editing: changing draft copy is routine, putting it
 * in front of guests on every channel is not.
 */
class ListingPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, 'listings.view');
    }

    public function view(User $user, Listing $listing): bool
    {
        return $this->access->allows($user, 'listings.view') && $this->withinScope($user, $listing);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'listings.create');
    }

    public function update(User $user, Listing $listing): bool
    {
        return $this->access->allows($user, 'listings.update') && $this->withinScope($user, $listing);
    }

    public function publish(User $user, Listing $listing): bool
    {
        return $this->access->allows($user, 'listings.publish') && $this->withinScope($user, $listing);
    }

    public function delete(User $user, Listing $listing): bool
    {
        return $this->access->allows($user, 'listings.delete') && $this->withinScope($user, $listing);
    }

    private function withinScope(User $user, Listing $listing): bool
    {
        $allowed = $this->access->restrictedPropertyIds($user);

        return $allowed === null || in_array($listing->property_id, $allowed, true);
    }
}
