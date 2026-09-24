<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Organization\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the platform looks like across every tenant.
 *
 * All of it computed from the records themselves. There is no rollup table,
 * for the same reason the revenue module has none: a summary that drifts from
 * the thing it summarises is worse than no summary, because somebody acts on it.
 *
 * Every query here is written with an explicit `organization_id` grouping rather
 * than relying on a global scope — these run *outside* any tenant, and a scoped
 * query would either return one tenant's data or nothing at all.
 */
class PlatformMetrics
{
    /**
     * The headline figures.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $today = CarbonImmutable::today();

        $organizations = DB::table('organizations')
            ->whereNull('deleted_at')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'organizations' => [
                'total' => (int) $organizations->sum(),
                'by_status' => $organizations->map(fn ($n): int => (int) $n)->all(),
                // Trials that have run out but have not been acted on. The
                // single most useful number on a platform dashboard, because it
                // is a list of conversations somebody owes a customer.
                'expired_trials' => DB::table('organizations')
                    ->whereNull('deleted_at')
                    ->where('status', 'trial')
                    ->whereNotNull('trial_ends_at')
                    ->where('trial_ends_at', '<', now())
                    ->count(),
                'new_this_month' => DB::table('organizations')
                    ->whereNull('deleted_at')
                    ->where('created_at', '>=', $today->startOfMonth())
                    ->count(),
            ],

            'users' => [
                'total' => DB::table('users')->whereNull('deleted_at')->count(),
                'platform_admins' => DB::table('users')
                    ->whereNull('deleted_at')
                    ->where('is_platform_admin', true)
                    ->count(),
                'active_last_30_days' => DB::table('users')
                    ->whereNull('deleted_at')
                    ->where('last_login_at', '>=', $today->subDays(30))
                    ->count(),
            ],

            'portfolio' => [
                'properties' => DB::table('properties')->whereNull('deleted_at')->count(),
                'units' => DB::table('units')->count(),
                'published_listings' => DB::table('listings')->whereNotNull('published_at')->count(),
            ],

            'trading' => [
                'reservations_this_month' => DB::table('reservations')
                    ->where('created_at', '>=', $today->startOfMonth())
                    ->count(),
                'reservations_total' => DB::table('reservations')->count(),
                'nights_sold_this_month' => DB::table('reservation_nights')
                    ->whereBetween('stay_date', [
                        $today->startOfMonth()->toDateString(),
                        $today->endOfMonth()->toDateString(),
                    ])
                    ->count(),
            ],

            // Deliberately not presented as platform revenue. These are amounts
            // our customers transacted, which is a different thing from what
            // they pay us, and conflating the two is how a board deck ends up
            // overstating a business by two orders of magnitude.
            'customer_transaction_volume' => $this->transactionVolume($today),

            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Tenant growth, by month.
     *
     * @return list<array{month: string, created: int}>
     */
    public function growth(int $months = 12): array
    {
        $from = CarbonImmutable::today()->startOfMonth()->subMonths(max(0, $months - 1));

        $rows = DB::table('organizations')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $from)
            ->selectRaw("to_char(created_at, 'YYYY-MM') as month, count(*) as created")
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $byMonth = $rows->pluck('created', 'month');
        $series = [];

        // Every month in the window, including the empty ones. A growth chart
        // that silently omits a month with no sign-ups draws a line through the
        // gap and flatters the trend.
        for ($i = 0; $i < $months; $i++) {
            $month = $from->addMonths($i)->format('Y-m');

            $series[] = ['month' => $month, 'created' => (int) ($byMonth[$month] ?? 0)];
        }

        return $series;
    }

    /**
     * The busiest tenants, by what they actually do rather than what they pay.
     *
     * @return list<array<string, mixed>>
     */
    public function busiestTenants(int $limit = 10): array
    {
        return DB::table('organizations as o')
            ->leftJoin('properties as p', function ($join): void {
                $join->on('p.organization_id', '=', 'o.id')->whereNull('p.deleted_at');
            })
            ->leftJoin('reservations as r', 'r.organization_id', '=', 'o.id')
            ->whereNull('o.deleted_at')
            ->groupBy('o.id', 'o.name', 'o.status')
            ->selectRaw(
                'o.id, o.name, o.status, '
                .'count(distinct p.id) as properties, '
                .'count(distinct r.id) as reservations'
            )
            ->orderByDesc('reservations')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'name' => $row->name,
                'status' => $row->status,
                'properties' => (int) $row->properties,
                'reservations' => (int) $row->reservations,
            ])
            ->all();
    }

    /**
     * One tenant in detail, for the console's tenant page.
     *
     * @return array<string, mixed>
     */
    public function forOrganization(Organization $organization): array
    {
        $id = $organization->getKey();

        return [
            'usage' => app(PlanEnforcement::class)->usage($organization),
            'features' => app(PlanEnforcement::class)->features($organization),
            'counts' => [
                'guests' => DB::table('guests')->where('organization_id', $id)->whereNull('deleted_at')->count(),
                'owners' => DB::table('owners')->where('organization_id', $id)->whereNull('deleted_at')->count(),
                'reservations' => DB::table('reservations')->where('organization_id', $id)->count(),
                'open_tasks' => DB::table('tasks')->where('organization_id', $id)
                    ->whereNotIn('status', ['completed', 'cancelled'])->count(),
                'channel_accounts' => DB::table('channel_accounts')->where('organization_id', $id)->count(),
                'api_keys' => DB::table('api_keys')->where('organization_id', $id)
                    ->whereNull('revoked_at')->count(),
                'webhook_endpoints' => DB::table('webhook_endpoints')->where('organization_id', $id)->count(),
            ],
            'last_activity_at' => DB::table('audit_logs')
                ->where('organization_id', $id)
                ->max('created_at'),
            'last_reservation_at' => DB::table('reservations')
                ->where('organization_id', $id)
                ->max('created_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionVolume(CarbonImmutable $today): array
    {
        $rows = DB::table('payments')
            ->where('status', 'captured')
            ->where('captured_at', '>=', $today->startOfMonth())
            ->selectRaw('base_currency, sum(captured_amount) as total, count(*) as payments')
            ->groupBy('base_currency')
            ->get();

        return [
            'period' => 'month to date',
            // Per currency, never summed across them. Adding euros to dollars
            // produces a number that is wrong in every currency.
            'by_currency' => $rows->map(fn (object $row): array => [
                'currency' => $row->base_currency,
                'amount' => (int) $row->total,
                'payments' => (int) $row->payments,
            ])->all(),
        ];
    }
}
