<?php

declare(strict_types=1);

namespace App\Domain\Users\Services;

use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the effective permission set for a user inside an organization.
 *
 * Effective permissions =
 *      union of the permissions of every role on the membership
 *      + direct `allow` overrides
 *      - direct `deny` overrides            (deny always wins)
 *
 * Results are cached per membership and invalidated whenever roles or
 * overrides change, because this runs on essentially every request.
 */
class AccessControl
{
    /** @var array<string, list<string>> in-request memoisation */
    private array $memo = [];

    public function __construct(private readonly TenantContext $tenancy) {}

    /**
     * The permissions a user holds inside an organization.
     *
     * @return list<string>
     */
    public function permissionsFor(User $user, Organization|string|null $organization = null): array
    {
        $organizationId = $this->resolveOrganizationId($organization);

        if ($organizationId === null) {
            return [];
        }

        $memoKey = $user->getKey().':'.$organizationId;

        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $membership = $this->membership($user, $organizationId);

        if ($membership === null || ! $membership->isActive()) {
            return $this->memo[$memoKey] = [];
        }

        return $this->memo[$memoKey] = Cache::remember(
            $this->cacheKey($membership),
            now()->addMinutes(30),
            fn (): array => $this->computePermissions($membership),
        );
    }

    /**
     * Whether the user holds a permission in the given (or current) organization.
     */
    public function allows(User $user, string $permission, Organization|string|null $organization = null): bool
    {
        // Platform administrators operate above the tenancy boundary.
        if ($user->isPlatformAdmin()) {
            return true;
        }

        return in_array($permission, $this->permissionsFor($user, $organization), true);
    }

    /**
     * Whether the user holds *every* permission listed.
     *
     * @param  list<string>  $permissions
     */
    public function allowsAll(User $user, array $permissions, Organization|string|null $organization = null): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        $held = $this->permissionsFor($user, $organization);

        foreach ($permissions as $permission) {
            if (! in_array($permission, $held, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the user holds at least one of the permissions listed.
     *
     * @param  list<string>  $permissions
     */
    public function allowsAny(User $user, array $permissions, Organization|string|null $organization = null): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        $held = $this->permissionsFor($user, $organization);

        foreach ($permissions as $permission) {
            if (in_array($permission, $held, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The user's membership in an organization, loaded with everything needed
     * to compute permissions.
     */
    public function membership(User $user, Organization|string|null $organization = null): ?Membership
    {
        $organizationId = $this->resolveOrganizationId($organization);

        if ($organizationId === null) {
            return null;
        }

        return Membership::query()
            ->withoutGlobalScope('organization')
            ->with(['roles.permissions', 'permissionOverrides'])
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organizationId)
            ->first();
    }

    /**
     * The property ids a member is restricted to, or null when unrestricted.
     *
     * @return list<string>|null
     */
    public function restrictedPropertyIds(User $user, Organization|string|null $organization = null): ?array
    {
        if ($user->isPlatformAdmin()) {
            return null;
        }

        $membership = $this->membership($user, $organization);

        if ($membership === null || ! $membership->restricted_to_properties) {
            return null;
        }

        return Cache::remember(
            $this->cacheKey($membership).':properties',
            now()->addMinutes(30),
            fn (): array => $membership->properties()->pluck('properties.id')->all(),
        );
    }

    /**
     * Drop cached permissions for a membership. Called whenever roles or
     * overrides change.
     */
    public function forget(Membership $membership): void
    {
        Cache::forget($this->cacheKey($membership));
        Cache::forget($this->cacheKey($membership).':properties');
        $this->memo = [];
    }

    /**
     * Drop every memoised value for the current request. Tests and long-running
     * queue workers use this after mutating access.
     */
    public function flushMemo(): void
    {
        $this->memo = [];
    }

    /**
     * @return list<string>
     */
    private function computePermissions(Membership $membership): array
    {
        $granted = [];

        foreach ($membership->roles as $role) {
            foreach ($role->permissions as $permission) {
                $granted[$permission->name] = true;
            }
        }

        $denied = [];

        foreach ($membership->permissionOverrides as $permission) {
            $effect = $permission->pivot->effect ?? 'allow';

            if ($effect === 'deny') {
                $denied[$permission->name] = true;
            } else {
                $granted[$permission->name] = true;
            }
        }

        return array_values(array_diff(array_keys($granted), array_keys($denied)));
    }

    private function cacheKey(Membership $membership): string
    {
        // The membership's updated_at is part of the key so that role changes,
        // which touch the membership, invalidate the entry automatically.
        return sprintf(
            'acl:%s:%s',
            $membership->getKey(),
            optional($membership->updated_at)->getTimestamp() ?? 0,
        );
    }

    private function resolveOrganizationId(Organization|string|null $organization): ?string
    {
        if ($organization instanceof Organization) {
            return $organization->getKey();
        }

        if (is_string($organization)) {
            return $organization;
        }

        return $this->tenancy->id();
    }
}
