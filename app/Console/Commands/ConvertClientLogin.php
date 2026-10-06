<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organization\Models\Organization;
use App\Domain\Owners\Services\ClientAccounts;
use App\Domain\Users\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Turn one existing login into a client login.
 *
 * The explicit step the provisioning command refuses to take on its own. It
 * replaces every role the membership holds with the client role, routes the
 * person to the client portal, restricts them to the account holder's
 * properties, and links them as the account holder's login if none exists.
 *
 * Refused until a platform owner exists (`platform:grant-admin`), because the
 * conversion removes administrative access and somebody has to still have it.
 * Refuses a platform owner's own account for the same reason.
 */
class ConvertClientLogin extends Command
{
    protected $signature = 'clients:convert-login
        {organization : The client organization (id or slug)}
        {email : The login to convert}
        {--reason= : Why, for the audit trail}
        {--dry-run : Show what would change and change nothing}';

    protected $description = 'Convert one existing membership into a read-only client login';

    public function handle(TenantContext $tenancy, ClientAccounts $clients): int
    {
        $hint = (string) $this->argument('organization');

        $organization = $tenancy->withoutScope(fn (): ?Organization => Organization::query()
            ->where('id', $hint)
            ->orWhere('slug', $hint)
            ->first());

        if ($organization === null) {
            $this->error(sprintf('No organization matches "%s".', $hint));

            return self::FAILURE;
        }

        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->error('No account exists for that email address.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $state = $clients->describe($organization);
            $current = collect($state['staff_logins'])->firstWhere('email', $user->email);

            $this->components->info(sprintf(
                '%s in %s currently holds [%s] and would become a client login. Nothing was changed.',
                $user->email,
                $organization->name,
                $current === null ? 'no staff roles' : implode(', ', $current['roles']),
            ));

            return self::SUCCESS;
        }

        try {
            $result = $clients->convertMembership($organization, $user, $this->option('reason'));
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s is now a client login for %s (was: %s).',
            $user->email,
            $organization->name,
            $result['previous_roles'] === [] ? 'no roles' : implode(', ', $result['previous_roles']),
        ));

        return self::SUCCESS;
    }
}
