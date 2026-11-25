<?php

declare(strict_types=1);

namespace App\Domain\Users\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Enums\PropertyStatus;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Enums\MembershipStatus;
use App\Domain\Users\Models\Invitation;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use App\Domain\Users\Support\PermissionRegistry;
use App\Domain\Users\Support\RoleRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An account deciding who on its team sees what.
 *
 * A member's access is a role, single permissions given or taken away on top
 * of it, and optionally a limit to some properties. All three were enforced
 * across the platform already; this is the account's own way to set them,
 * with three rules no screen can get round:
 *
 * - nobody gives anyone more than they hold themselves, so a manager who may
 *   edit the team cannot hand out the ledger they cannot see;
 * - nobody changes their own access, which is how people lock themselves
 *   out, or let themselves in;
 * - an account always keeps at least one active member who can manage its
 *   team, or nobody could ever change anything again.
 */
class TeamAccess
{
    /**
     * Roles that are not the account's to hand out: the platform's own, and
     * the client of a managed account, which the platform sets up.
     */
    private const NOT_OFFERED = [RoleRegistry::SUPER_ADMIN, RoleRegistry::OWNER];

    /** The permission that makes somebody able to manage the team. */
    private const MANAGES_TEAM = 'users.update';

    public function __construct(
        private readonly AccessControl $access,
        private readonly MembershipAccess $writer,
        private readonly InvitationService $invitations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * One member as the team screen shows them.
     *
     * @return array<string, mixed>
     */
    public function describe(Membership $membership, ?User $viewer = null): array
    {
        $membership->loadMissing(['user', 'roles.permissions', 'permissionOverrides', 'properties']);
        [$allow, $deny] = $this->overrides($membership);

        return [
            'id' => $membership->getKey(),
            'user' => [
                'id' => $membership->user?->getKey(),
                'name' => $membership->user?->fullName(),
                'email' => $membership->user?->email,
                'last_login_at' => $membership->user?->last_login_at?->toIso8601String(),
            ],
            'status' => $membership->status->value,
            'job_title' => $membership->job_title,
            'joined_at' => $membership->joined_at?->toIso8601String(),
            'roles' => $membership->roles->map(fn (Role $role): array => [
                'id' => $role->getKey(),
                'slug' => $role->slug,
                'name' => $role->name,
            ])->values()->all(),
            'allow' => $allow,
            'deny' => $deny,
            'permissions' => $this->effective($membership->roles, $allow, $deny),
            'restricted_to_properties' => (bool) $membership->restricted_to_properties,
            'property_ids' => $membership->restricted_to_properties
                ? $membership->properties->pluck('id')->sort()->values()->all()
                : [],
            'is_you' => $viewer !== null && $membership->user_id === $viewer->getKey(),
        ];
    }

    /**
     * An invitation still waiting, as the team screen shows it.
     *
     * @return array<string, mixed>
     */
    public function describeInvitation(Invitation $invitation): array
    {
        $invitation->loadMissing('roles');

        return [
            'id' => $invitation->getKey(),
            'email' => $invitation->email,
            'first_name' => $invitation->first_name,
            'last_name' => $invitation->last_name,
            'job_title' => $invitation->job_title,
            'roles' => $invitation->roles->map(fn (Role $role): array => [
                'id' => $role->getKey(),
                'slug' => $role->slug,
                'name' => $role->name,
            ])->values()->all(),
            'access' => $invitation->access ?? (object) [],
            'expires_at' => $invitation->expires_at?->toIso8601String(),
        ];
    }

    /**
     * What can be chosen: every permission by area, the roles the account may
     * hand out, and its properties.
     *
     * @return array<string, mixed>
     */
    public function options(Organization $organization): array
    {
        $areas = [];

        foreach (PermissionRegistry::groups() as $area => $permissions) {
            $areas[] = [
                'name' => $area,
                'permissions' => collect($permissions)
                    ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
                    ->values()->all(),
            ];
        }

        return [
            'areas' => $areas,
            'roles' => $this->offeredRoles($organization)
                ->map(fn (Role $role): array => [
                    'id' => $role->getKey(),
                    'slug' => $role->slug,
                    'name' => $role->name,
                    'description' => $role->description,
                    'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
                ])->values()->all(),
            'properties' => Property::query()
                ->withoutGlobalScope('organization')
                ->where('organization_id', $organization->getKey())
                ->where('status', '!=', PropertyStatus::Archived->value)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Property $property): array => ['id' => $property->getKey(), 'name' => $property->name])
                ->all(),
        ];
    }

    /**
     * Change what a member sees.
     *
     * @param  array{role_ids?: list<string>, allow?: list<string>, deny?: list<string>, restricted_to_properties?: bool, property_ids?: list<string>, job_title?: string|null}  $data
     */
    public function change(Membership $membership, array $data, User $actor): Membership
    {
        $this->refuseOwnSeat($membership, $actor, 'access');
        $membership->loadMissing(['roles.permissions', 'permissionOverrides', 'properties']);

        $organization = Organization::query()->withoutGlobalScopes()->findOrFail($membership->organization_id);
        [$allowNow, $denyNow] = $this->overrides($membership);
        $before = $membership->isActive() ? $this->effective($membership->roles, $allowNow, $denyNow) : [];

        $roles = array_key_exists('role_ids', $data) ? $this->roles($organization, $data['role_ids']) : $membership->roles;
        $allow = array_values(array_unique($data['allow'] ?? $allowNow));
        $deny = array_values(array_unique($data['deny'] ?? $denyNow));
        $this->refuseOverlap($allow, $deny);

        $after = $this->effective($roles, $allow, $deny);
        $this->refuseMoreThanHeld($actor, $organization, array_diff($this->rolePermissions($roles), $before), 'role_ids');
        $this->refuseMoreThanHeld($actor, $organization, array_diff($allow, $before), 'allow');

        $restricted = (bool) ($data['restricted_to_properties'] ?? $membership->restricted_to_properties);
        $propertyIds = $restricted ? array_values(array_unique($data['property_ids'] ?? $membership->properties->pluck('id')->all())) : [];
        $this->refuseProperties($organization, $actor, $restricted, $propertyIds,
            array_key_exists('restricted_to_properties', $data) || array_key_exists('property_ids', $data));

        if ($membership->isActive()) {
            $this->keepSomeoneWhoManagesTheTeam($organization, $membership, in_array(self::MANAGES_TEAM, $after, true), 'role_ids');
        }

        DB::transaction(function () use ($membership, $data, $roles, $allow, $deny, $restricted, $propertyIds): void {
            if (array_key_exists('job_title', $data)) {
                $membership->forceFill(['job_title' => $data['job_title']])->save();
            }

            $this->writer->write($membership, $roles->pluck('id')->all(), $allow, $deny, $restricted, $propertyIds);
        });

        $this->audit->record(
            action: 'membership.access_changed',
            subject: $membership,
            oldValues: ['permissions' => $before],
            newValues: ['permissions' => $after, 'restricted_to_properties' => $restricted, 'property_ids' => $propertyIds],
            description: sprintf('Changed what %s can see', $membership->user?->email ?? 'a member'),
        );

        return $membership->fresh(['user', 'roles.permissions', 'permissionOverrides', 'properties']);
    }

    /**
     * Switch a member off, or back on.
     */
    public function setActive(Membership $membership, bool $active, User $actor): Membership
    {
        $this->refuseOwnSeat($membership, $actor, 'status');
        $organization = Organization::query()->withoutGlobalScopes()->findOrFail($membership->organization_id);

        if (! $active && $membership->isActive()) {
            $this->keepSomeoneWhoManagesTheTeam($organization, $membership, false, 'status');
        }

        $membership->forceFill(['status' => $active ? MembershipStatus::Active : MembershipStatus::Suspended])->save();
        $this->access->forget($membership);
        $this->access->flushMemo();

        $this->audit->record(
            action: $active ? 'membership.reactivated' : 'membership.deactivated',
            subject: $membership,
            description: sprintf('%s %s', $active ? 'Switched on' : 'Switched off', $membership->user?->email ?? 'a member'),
        );

        return $membership->fresh(['user', 'roles.permissions', 'permissionOverrides', 'properties']);
    }

    /**
     * Invite somebody with the access they will have when they accept.
     *
     * @param  array{email: string, first_name?: string|null, last_name?: string|null, job_title?: string|null, role_ids: list<string>, allow?: list<string>, deny?: list<string>, restricted_to_properties?: bool, property_ids?: list<string>}  $data
     */
    public function invite(Organization $organization, array $data, User $actor): Invitation
    {
        $roles = $this->roles($organization, $data['role_ids']);

        if ($roles->isEmpty()) {
            throw ValidationException::withMessages(['role_ids' => __('Choose a role for them.')]);
        }

        $allow = array_values(array_unique($data['allow'] ?? []));
        $deny = array_values(array_unique($data['deny'] ?? []));
        $this->refuseOverlap($allow, $deny);
        $this->refuseMoreThanHeld($actor, $organization, $this->rolePermissions($roles), 'role_ids');
        $this->refuseMoreThanHeld($actor, $organization, $allow, 'allow');

        $restricted = (bool) ($data['restricted_to_properties'] ?? false);
        $propertyIds = $restricted ? array_values(array_unique($data['property_ids'] ?? [])) : [];
        $this->refuseProperties($organization, $actor, $restricted, $propertyIds, true);

        return $this->invitations->invite(
            organization: $organization,
            email: $data['email'],
            roleIds: $roles->pluck('id')->all(),
            invitedBy: $actor,
            firstName: $data['first_name'] ?? null,
            lastName: $data['last_name'] ?? null,
            jobTitle: $data['job_title'] ?? null,
            access: [
                'allow' => $allow,
                'deny' => $deny,
                'restricted_to_properties' => $restricted,
                'property_ids' => $propertyIds,
            ],
        );
    }

    // ------------------------------------------------------------------

    /**
     * @return Collection<int, Role>
     */
    private function offeredRoles(Organization $organization): Collection
    {
        return Role::query()
            ->availableTo($organization)
            ->whereNotIn('slug', self::NOT_OFFERED)
            ->with('permissions')
            ->orderBy('name')
            ->get();
    }

    /**
     * The roles asked for, from those the account may hand out.
     *
     * @param  list<string>  $ids
     * @return Collection<int, Role>
     */
    private function roles(Organization $organization, array $ids): Collection
    {
        $roles = $this->offeredRoles($organization)->whereIn('id', $ids)->values();

        if ($roles->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['role_ids' => __('One of those roles cannot be given here.')]);
        }

        return $roles;
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function overrides(Membership $membership): array
    {
        $allow = [];
        $deny = [];

        foreach ($membership->permissionOverrides as $permission) {
            if (($permission->pivot->effect ?? 'allow') === 'deny') {
                $deny[] = $permission->name;
            } else {
                $allow[] = $permission->name;
            }
        }

        sort($allow);
        sort($deny);

        return [$allow, $deny];
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @return list<string>
     */
    private function rolePermissions(Collection $roles): array
    {
        $roles->loadMissing('permissions');

        return $roles->flatMap(fn (Role $role) => $role->permissions->pluck('name'))->unique()->values()->all();
    }

    /**
     * The same sum {@see AccessControl} makes: the roles, plus what is given,
     * minus what is taken away.
     *
     * @param  Collection<int, Role>  $roles
     * @param  list<string>  $allow
     * @param  list<string>  $deny
     * @return list<string>
     */
    private function effective(Collection $roles, array $allow, array $deny): array
    {
        $held = array_values(array_diff(array_unique(array_merge($this->rolePermissions($roles), $allow)), $deny));
        sort($held);

        return $held;
    }

    private function refuseOwnSeat(Membership $membership, User $actor, string $field): void
    {
        if ($membership->user_id === $actor->getKey()) {
            throw ValidationException::withMessages([
                $field => __('You cannot change your own access. Ask another admin on your team.'),
            ]);
        }
    }

    /**
     * @param  list<string>  $allow
     * @param  list<string>  $deny
     */
    private function refuseOverlap(array $allow, array $deny): void
    {
        foreach (['allow' => $allow, 'deny' => $deny] as $field => $names) {
            foreach ($names as $name) {
                if (! PermissionRegistry::exists($name)) {
                    throw ValidationException::withMessages([$field => __('":name" is not something that can be given or taken away.', ['name' => $name])]);
                }
            }
        }

        if (array_intersect($allow, $deny) !== []) {
            throw ValidationException::withMessages(['deny' => __('The same permission cannot be both given and taken away.')]);
        }
    }

    /**
     * @param  list<string>  $gained
     */
    private function refuseMoreThanHeld(User $actor, Organization $organization, array $gained, string $field): void
    {
        if ($gained === [] || $actor->isPlatformAdmin()) {
            return;
        }

        $missing = array_values(array_diff($gained, $this->access->permissionsFor($actor, $organization)));

        if ($missing !== []) {
            $labels = PermissionRegistry::flat();

            throw ValidationException::withMessages([
                $field => __('You cannot give access you do not have yourself: :list.', [
                    'list' => implode(', ', array_map(fn (string $name): string => $labels[$name] ?? $name, array_slice($missing, 0, 5))),
                ]),
            ]);
        }
    }

    /**
     * @param  list<string>  $propertyIds
     */
    private function refuseProperties(Organization $organization, User $actor, bool $restricted, array $propertyIds, bool $changing): void
    {
        if (! $restricted) {
            $propertyIds = [];
        } elseif ($propertyIds === []) {
            throw ValidationException::withMessages(['property_ids' => __('Choose at least one property, or let them see all of them.')]);
        }

        $known = Property::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->whereIn('id', $propertyIds)
            ->count();

        if ($known !== count($propertyIds)) {
            throw ValidationException::withMessages(['property_ids' => __('One of those properties is not in this account.')]);
        }

        // Somebody limited to some properties cannot let anybody see more.
        $own = $actor->isPlatformAdmin() ? null : $this->access->restrictedPropertyIds($actor, $organization);

        if ($changing && $own !== null && (! $restricted || array_diff($propertyIds, $own) !== [])) {
            throw ValidationException::withMessages([
                'property_ids' => __('You can only give access to the properties you can see yourself.'),
            ]);
        }
    }

    /**
     * Refuse a change that would leave nobody able to manage the team.
     */
    private function keepSomeoneWhoManagesTheTeam(Organization $organization, Membership $changing, bool $stillManages, string $field): void
    {
        if ($stillManages) {
            return;
        }

        $others = Membership::query()
            ->withoutGlobalScope('organization')
            ->with('user')
            ->where('organization_id', $organization->getKey())
            ->where('status', MembershipStatus::Active->value)
            ->whereKeyNot($changing->getKey())
            ->get();

        foreach ($others as $other) {
            if ($other->user !== null && in_array(self::MANAGES_TEAM, $this->access->permissionsFor($other->user, $organization), true)) {
                return;
            }
        }

        throw ValidationException::withMessages([
            $field => __('This would leave nobody who can manage the team. Give someone else that access first.'),
        ]);
    }
}
