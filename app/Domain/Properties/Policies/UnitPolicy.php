<?php

declare(strict_types=1);

namespace App\Domain\Properties\Policies;

use App\Domain\Properties\Models\Unit;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Units inherit their access rules from the property they belong to: a member
 * restricted to one building cannot reach the units in another.
 */
class UnitPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, 'units.view');
    }

    public function view(User $user, Unit $unit): bool
    {
        return $this->access->allows($user, 'units.view') && $this->withinScope($user, $unit);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'units.create');
    }

    public function update(User $user, Unit $unit): bool
    {
        return $this->access->allows($user, 'units.update') && $this->withinScope($user, $unit);
    }

    public function delete(User $user, Unit $unit): bool
    {
        return $this->access->allows($user, 'units.delete') && $this->withinScope($user, $unit);
    }

    private function withinScope(User $user, Unit $unit): bool
    {
        $allowed = $this->access->restrictedPropertyIds($user);

        return $allowed === null || in_array($unit->property_id, $allowed, true);
    }
}
