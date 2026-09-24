<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Platform\Services\PlatformAuditLogger;
use App\Domain\Users\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Everybody with an account, across every tenant.
 *
 * The one thing this endpoint grants is platform administration itself, which
 * makes it the most sensitive write in the product: it is the only way to create
 * somebody who can reach this console at all. So it is narrow — the flag and
 * nothing else — and every change to it is logged twice.
 *
 * It does not edit a person's name, email or password. Those belong to the
 * person, and a platform operator changing a customer's email is
 * indistinguishable from an account takeover.
 */
class PlatformUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->withCount('memberships');

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';

            $query->where(fn ($q) => $q
                ->where('email', 'ilike', $term)
                ->orWhere('first_name', 'ilike', $term)
                ->orWhere('last_name', 'ilike', $term));
        }

        if ($request->boolean('platform_admins_only')) {
            $query->where('is_platform_admin', true);
        }

        $users = $query->orderByDesc('created_at')->paginate($this->perPage());

        return response()->json([
            'data' => collect($users->items())->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->fullName(),
                'email' => $user->email,
                'status' => $user->status,
                'is_platform_admin' => (bool) $user->is_platform_admin,
                'mfa_enabled' => (bool) $user->mfa_enabled,
                'email_verified' => $user->email_verified_at !== null,
                'organizations_count' => (int) $user->memberships_count,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Which organizations a person belongs to.
     *
     * Asked when somebody emails support from an address nobody recognises.
     */
    public function show(User $user): JsonResponse
    {
        $memberships = $user->memberships()
            ->withoutGlobalScope('organization')
            ->with(['organization', 'roles'])
            ->get();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->fullName(),
                'email' => $user->email,
                'status' => $user->status,
                'is_platform_admin' => (bool) $user->is_platform_admin,
                'mfa_enabled' => (bool) $user->mfa_enabled,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'organizations' => $memberships->map(fn ($m): array => [
                    'organization_id' => $m->organization_id,
                    'name' => $m->organization?->name,
                    'status' => $m->status,
                    'roles' => $m->roles->pluck('name')->all(),
                ])->values(),
            ],
        ]);
    }

    /**
     * Grant or revoke platform administration.
     *
     * Two guards, both learned the hard way in every system that has this
     * feature: an operator cannot revoke their own flag (which would lock them
     * out mid-task), and the last platform administrator cannot be demoted
     * (which would lock everybody out permanently and require a database
     * console to undo).
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'is_platform_admin' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $actor = $this->currentUser();
        $granting = (bool) $data['is_platform_admin'];

        if ($user->is($actor) && ! $granting) {
            return response()->json([
                'message' => 'You cannot remove your own platform administration. Ask another administrator.',
            ], 422);
        }

        if (! $granting && $user->is_platform_admin) {
            $remaining = User::query()
                ->where('is_platform_admin', true)
                ->whereKeyNot($user->getKey())
                ->count();

            if ($remaining === 0) {
                return response()->json([
                    'message' => 'This is the only platform administrator. Grant the flag to somebody else first.',
                ], 422);
            }
        }

        if ((bool) $user->is_platform_admin === $granting) {
            return response()->json(['message' => 'No change was needed.']);
        }

        DB::transaction(function () use ($user, $granting, $data, $actor): void {
            $user->forceFill(['is_platform_admin' => $granting])->save();

            // Revoking leaves their tokens alone: their tenant access is
            // unaffected and signing somebody out of their own company because
            // they stopped being a platform operator would be surprising. The
            // console's middleware re-reads the flag on every request, so their
            // access here stops immediately regardless.
            //
            // Recorded in the platform's own trail, not a tenant's: this action
            // belongs to no organization, and the tenant audit table rightly
            // refuses a row it could never attribute.
            app(PlatformAuditLogger::class)->record(
                action: $granting ? 'platform.admin_granted' : 'platform.admin_revoked',
                actor: $actor,
                subject: $user,
                description: sprintf('%s platform administration for %s: %s',
                    $granting ? 'Granted' : 'Revoked',
                    $user->email,
                    $data['reason'],
                ),
                context: ['subject_email' => $user->email, 'reason' => $data['reason']],
            );
        });

        return response()->json([
            'message' => $granting
                ? 'Platform administration granted.'
                : 'Platform administration revoked. Their access to their own organizations is unchanged.',
            'data' => [
                'id' => $user->id,
                'email' => $user->email,
                'is_platform_admin' => $granting,
            ],
        ]);
    }
}
