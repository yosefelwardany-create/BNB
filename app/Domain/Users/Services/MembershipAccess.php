<?php

declare(strict_types=1);

namespace App\Domain\Users\Services;

use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\Permission;

/**
 * Writes what one member of an account may see.
 *
 * Shared by the team screen and by an invitation being accepted, so a person
 * who joins gets exactly the access they were invited with. The checks on who
 * may give what are {@see TeamAccess}'s; this only records the result.
 */
class MembershipAccess
{
    public function __construct(private readonly AccessControl $access) {}

    /**
     * @param  list<string>|null  $roleIds  null leaves the roles as they are
     * @param  list<string>  $allow  permissions given on top of the roles
     * @param  list<string>  $deny  permissions taken away from the roles
     * @param  list<string>  $propertyIds  the properties a limited member may see
     */
    public function write(
        Membership $membership,
        ?array $roleIds,
        array $allow,
        array $deny,
        bool $restricted,
        array $propertyIds,
    ): void {
        if ($roleIds !== null) {
            $membership->roles()->sync($roleIds);
        }

        $ids = Permission::query()->whereIn('name', array_merge($allow, $deny))->pluck('id', 'name');
        $overrides = [];

        foreach ($allow as $name) {
            if (isset($ids[$name])) {
                $overrides[$ids[$name]] = ['effect' => 'allow'];
            }
        }

        foreach ($deny as $name) {
            if (isset($ids[$name])) {
                $overrides[$ids[$name]] = ['effect' => 'deny'];
            }
        }

        $membership->permissionOverrides()->sync($overrides);

        $properties = $restricted
            ? Property::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $membership->organization_id)
                ->whereIn('id', $propertyIds)
                ->pluck('id')
                ->all()
            : [];

        $membership->properties()->sync($properties);
        $membership->forceFill(['restricted_to_properties' => $restricted])->save();

        // The cache key carries the membership's timestamp, so touching it is
        // what makes the new access take effect on the member's next request.
        $membership->touch();
        $this->access->forget($membership);
        $this->access->flushMemo();
    }
}
