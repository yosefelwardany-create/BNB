<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organization\Models\Organization;
use App\Domain\Owners\Services\ClientAccounts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Make every organization a complete client account.
 *
 * Additive and idempotent, like `properties:ensure-listings`: it creates the
 * account-holder owner record, the blanket 10% agreement and an ownership row
 * for every property nobody owns, and it changes nothing that already exists.
 * Run it as often as you like; it runs on every deploy.
 *
 * What it deliberately does *not* do is touch a single membership. Which
 * logins are the client's and which are the management company's own staff is
 * not something a command can tell from the data, so conversion is a separate,
 * explicit, one-login-at-a-time step: `clients:convert-login`. This command
 * only reports the logins still holding staff roles so somebody can decide.
 */
class ProvisionClientAccounts extends Command
{
    protected $signature = 'clients:provision
        {--organization= : Only this organization (id or slug)}
        {--dry-run : Report what would be created and create nothing}';

    protected $description = 'Ensure every organization has a client account holder, a management agreement and ownership of its properties';

    public function handle(TenantContext $tenancy, ClientAccounts $clients): int
    {
        $organizations = $tenancy->withoutScope(function () {
            $query = Organization::query()->orderBy('created_at');

            if ($this->option('organization') !== null) {
                $hint = (string) $this->option('organization');
                $query->where(fn ($q) => $q->where('id', $hint)->orWhere('slug', $hint));
            }

            return $query->get();
        });

        if ($organizations->isEmpty()) {
            $this->components->warn('No organizations matched.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach ($organizations as $organization) {
            $before = $clients->describe($organization);

            if (! $dryRun) {
                $holder = $clients->ensureAccountHolder($organization);
                $clients->ensureAgreement($organization, $holder);
                $clients->ensureOwnerships($organization);
            }

            $after = $dryRun ? $before : $clients->describe($organization);

            $rows[] = [
                $organization->name,
                $before['account_holder'] === null ? ($dryRun ? 'would create' : 'created') : 'present',
                $before['agreement'] === null ? ($dryRun ? 'would create' : 'created') : 'present',
                $dryRun
                    ? sprintf('%d would be attributed', $before['properties_without_ownership'])
                    : sprintf('%d attributed, %d unowned', $before['properties_without_ownership'] - $after['properties_without_ownership'], $after['properties_without_ownership']),
                (string) $after['client_logins'],
                count($after['staff_logins']) === 0
                    ? 'none'
                    : implode(', ', array_map(fn (array $s): string => (string) $s['email'], $after['staff_logins'])),
            ];
        }

        $this->table(
            ['Account', 'Holder', 'Agreement', 'Properties', 'Client logins', 'Staff logins (not converted)'],
            $rows,
        );

        $this->newLine();
        $this->components->info($dryRun
            ? 'Dry run: nothing was created.'
            : 'Client accounts are complete. Memberships were not changed; convert a login with clients:convert-login.');

        return self::SUCCESS;
    }
}
