<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Platform\Services\PlatformAuditLogger;
use App\Domain\Users\Models\User;
use Illuminate\Console\Command;

/**
 * Makes a person the platform owner, or stops them being one.
 *
 * The only way to grant owner privileges from outside the application. The
 * flag is deliberately not mass-assignable and no public endpoint touches it:
 * registration creates clients, and nothing a client controls can lead here.
 * Every grant and revocation is written to the platform audit trail.
 *
 * Run this *before* converting any membership to a client: the owner has to
 * exist before anybody loses administrative access, or the first conversion
 * locks everyone out.
 */
class GrantPlatformAdmin extends Command
{
    protected $signature = 'platform:grant-admin
        {email : The account that becomes (or stops being) a platform owner}
        {--revoke : Remove the privilege instead of granting it}
        {--reason= : Why, for the audit trail}';

    protected $description = 'Grant or revoke platform owner privileges for a user account';

    public function handle(PlatformAuditLogger $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error(sprintf('No account exists for %s. Create the account first (sign-up, invitation or a client account).', $email));

            return self::FAILURE;
        }

        $revoke = (bool) $this->option('revoke');

        if ($revoke && User::query()->where('is_platform_admin', true)->count() <= 1 && $user->isPlatformAdmin()) {
            $this->error('Refusing: this is the last platform owner. Grant another account first.');

            return self::FAILURE;
        }

        if ($user->isPlatformAdmin() === ! $revoke) {
            $this->info(sprintf('%s is already %s.', $email, $revoke ? 'not a platform owner' : 'a platform owner'));

            return self::SUCCESS;
        }

        // forceFill on purpose: the attribute is guarded against every
        // ordinary write path and this command is the sanctioned exception.
        $user->forceFill(['is_platform_admin' => ! $revoke])->save();

        $audit->record(
            action: $revoke ? 'platform.admin_revoked' : 'platform.admin_granted',
            actor: null,
            subject: $user,
            description: sprintf(
                '%s platform owner privileges for %s from the console command.',
                $revoke ? 'Revoked' : 'Granted',
                $email,
            ),
            context: array_filter(['reason' => $this->option('reason')]),
        );

        $this->info(sprintf('%s is now %s.', $email, $revoke ? 'no longer a platform owner' : 'a platform owner'));

        return self::SUCCESS;
    }
}
