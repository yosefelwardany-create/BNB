<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Removes the Demo Hospitality Group sample account and its demo logins.
 *
 * The demo seeder's logins share a published password, so on a live platform
 * the account is a door left open. It is replaced by the Bogota Colombia
 * sample account and runs only once that account exists.
 *
 * Everything belonging to the organization goes with it, in one transaction.
 * Three links refuse a delete (journal lines to ledger accounts, upsell orders
 * to products, reservations to properties), so those rows go first; the
 * organization's audit rows go too, rather than being left with no account.
 * A demo login is removed only when it belongs to no other account and is not
 * a platform owner. Nothing outside the demo account is touched.
 */
class RetireDemoHospitalitySeeder extends Seeder
{
    private const ORGANIZATION_SLUG = 'demo-hospitality-group';

    public function run(): void
    {
        $tenancy = app(TenantContext::class);

        $replacement = $tenancy->withoutScope(
            fn () => Organization::query()->where('slug', BogotaColombiaSampleSeeder::SLUG)->exists(),
        );

        if (! $replacement) {
            $this->command?->warn('Demo Hospitality Group was kept: the Bogota Colombia account does not exist yet.');

            return;
        }

        $organizationId = DB::table('organizations')->where('slug', self::ORGANIZATION_SLUG)->value('id');

        $removedLogins = DB::transaction(function () use ($organizationId): int {
            if ($organizationId !== null) {
                foreach (['journal_lines', 'upsell_orders', 'reservations', 'audit_logs'] as $table) {
                    DB::table($table)->where('organization_id', $organizationId)->delete();
                }

                // Straight to the table: the account is going, and model events
                // would write audit rows pointing at it as it went.
                DB::table('organizations')->where('id', $organizationId)->delete();
            }

            return $this->removeDemoLogins();
        });

        if ($organizationId === null && $removedLogins === 0) {
            return;
        }

        $this->command?->info(sprintf(
            'Demo Hospitality Group removed%s, with %d demo login(s).',
            $organizationId === null ? ' (already gone)' : '',
            $removedLogins,
        ));
    }

    private function removeDemoLogins(): int
    {
        $users = User::withTrashed()
            ->where(fn ($query) => $query
                ->where('email', 'like', '%@demo-hospitality.test')
                ->orWhere('email', 'platform@habitat.test'))
            ->where('is_platform_admin', false)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('memberships')
                ->whereColumn('memberships.user_id', 'users.id'))
            ->pluck('id');

        if ($users->isEmpty()) {
            return 0;
        }

        DB::table('personal_access_tokens')
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', $users)
            ->delete();

        return DB::table('users')->whereIn('id', $users)->delete();
    }
}
