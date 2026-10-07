<?php

declare(strict_types=1);

namespace App\Domain\Organization\Services;

use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a client account, for good.
 *
 * Everything the organization holds goes with it, in one transaction: either
 * all of it is gone or none of it is. Three links refuse a delete (journal
 * lines to ledger accounts, upsell orders to products, reservations to
 * properties), so those rows go first; the account's own audit trail goes too,
 * rather than being left pointing at nothing. The platform's audit keeps the
 * account's name, so the deletion itself stays on record.
 *
 * A login of the account is removed only when it belongs to no other account
 * and is not a platform owner. Nothing outside the account is touched.
 */
class AccountRemoval
{
    /**
     * @return array{logins_removed: list<string>, logins_kept: list<string>}
     */
    public function remove(Organization $organization): array
    {
        $organizationId = (string) $organization->getKey();

        return DB::transaction(function () use ($organizationId): array {
            $memberIds = DB::table('memberships')
                ->where('organization_id', $organizationId)
                ->pluck('user_id')
                ->unique()
                ->values();

            foreach (['journal_lines', 'upsell_orders', 'reservations', 'audit_logs'] as $table) {
                DB::table($table)->where('organization_id', $organizationId)->delete();
            }

            // Straight to the table: model events would write audit rows that
            // point at the account as it goes.
            DB::table('organizations')->where('id', $organizationId)->delete();

            if ($memberIds->isEmpty()) {
                return ['logins_removed' => [], 'logins_kept' => []];
            }

            $members = User::withTrashed()->whereIn('id', $memberIds)->get(['id', 'email', 'is_platform_admin']);

            $removable = $members
                ->reject(fn (User $user): bool => $user->isPlatformAdmin())
                ->reject(fn (User $user): bool => DB::table('memberships')->where('user_id', $user->getKey())->exists());

            if ($removable->isNotEmpty()) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', (new User)->getMorphClass())
                    ->whereIn('tokenable_id', $removable->modelKeys())
                    ->delete();

                DB::table('users')->whereIn('id', $removable->modelKeys())->delete();
            }

            return [
                'logins_removed' => $removable->pluck('email')->values()->all(),
                'logins_kept' => $members->diff($removable)->pluck('email')->values()->all(),
            ];
        });
    }
}
