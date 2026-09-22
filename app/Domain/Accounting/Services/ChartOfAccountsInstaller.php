<?php

declare(strict_types=1);

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Accounting\Support\DefaultChartOfAccounts;
use App\Domain\Organization\Models\Organization;
use App\Support\Tenancy\TenantContext;

/**
 * Installs (and later reconciles) the default chart of accounts for an
 * organization.
 *
 * Running it a second time is safe: existing accounts are left untouched and
 * only missing system accounts are added, which is how new system accounts
 * introduced by a platform release reach existing tenants.
 */
class ChartOfAccountsInstaller
{
    public function __construct(private readonly TenantContext $tenancy) {}

    /**
     * @return int number of accounts created
     */
    public function install(Organization $organization): int
    {
        return $this->tenancy->runAs($organization, function () use ($organization): int {
            $existing = LedgerAccount::query()
                ->where('organization_id', $organization->getKey())
                ->pluck('code')
                ->all();

            $created = 0;

            foreach (DefaultChartOfAccounts::accounts() as $account) {
                if (in_array($account['code'], $existing, true)) {
                    continue;
                }

                LedgerAccount::query()->create([
                    'organization_id' => $organization->getKey(),
                    'code' => $account['code'],
                    'name' => $account['name'],
                    'type' => $account['type'],
                    'system_key' => $account['system_key'],
                    'description' => $account['description'],
                    'currency' => $organization->base_currency,
                    'is_system' => $account['system_key'] !== null,
                    'is_active' => true,
                ]);

                $created++;
            }

            return $created;
        });
    }

    /**
     * Resolve a system account by its key. Posting rules use this rather than
     * hard-coded account codes so a customer can renumber their chart.
     */
    public function account(Organization|string $organization, string $systemKey): LedgerAccount
    {
        $organizationId = $organization instanceof Organization ? $organization->getKey() : $organization;

        $account = LedgerAccount::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('system_key', $systemKey)
            ->first();

        if ($account === null) {
            throw new \RuntimeException(sprintf(
                'The system ledger account [%s] is missing for organization [%s]. Run the chart of accounts installer.',
                $systemKey,
                $organizationId,
            ));
        }

        return $account;
    }
}
