<?php

declare(strict_types=1);

namespace App\Domain\Guests\Policies;

use App\Domain\Guests\Models\Guest;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Guest records carry personal data, so merging and exporting are separate,
 * higher permissions than viewing: a merge is irreversible from the user's
 * point of view, and an export takes the data out of the platform entirely.
 */
class GuestPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, 'guests.view');
    }

    public function view(User $user, Guest $guest): bool
    {
        return $this->access->allows($user, 'guests.view');
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'guests.create');
    }

    public function update(User $user, Guest $guest): bool
    {
        return $this->access->allows($user, 'guests.update');
    }

    public function merge(User $user, Guest $guest): bool
    {
        return $this->access->allows($user, 'guests.merge');
    }

    public function export(User $user): bool
    {
        return $this->access->allows($user, 'guests.export');
    }
}
