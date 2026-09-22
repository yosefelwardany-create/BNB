<?php

declare(strict_types=1);

namespace App\Domain\Properties\Policies;

use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;

/**
 * Record-level authorization for properties.
 *
 * The permission check answers "may this person do this kind of thing"; the
 * property restriction answers "to this particular property". Both must pass,
 * which is what confines an on-site manager to their own building.
 *
 * Tenant isolation is handled beneath this by the model's global scope, so a
 * property from another organization never reaches a policy method.
 */
class PropertyPolicy
{
    public function __construct(private readonly AccessControl $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user, 'properties.view');
    }

    public function view(User $user, Property $property): bool
    {
        return $this->access->allows($user, 'properties.view')
            && $this->withinScope($user, $property);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user, 'properties.create');
    }

    public function update(User $user, Property $property): bool
    {
        return $this->access->allows($user, 'properties.update')
            && $this->withinScope($user, $property);
    }

    public function delete(User $user, Property $property): bool
    {
        return $this->access->allows($user, 'properties.delete')
            && $this->withinScope($user, $property);
    }

    public function activate(User $user, Property $property): bool
    {
        return $this->update($user, $property);
    }

    /**
     * Access credentials — door codes, Wi-Fi passwords, alarm instructions —
     * are shown only to people who also manage the property's operations.
     */
    public function viewAccessDetails(User $user, Property $property): bool
    {
        return $this->withinScope($user, $property)
            && $this->access->allowsAny($user, [
                'properties.update',
                'tasks.update',
                'locks.view',
            ]);
    }

    /**
     * Whether a member restricted to certain properties may touch this one.
     */
    private function withinScope(User $user, Property $property): bool
    {
        $allowed = $this->access->restrictedPropertyIds($user);

        return $allowed === null || in_array($property->getKey(), $allowed, true);
    }
}
