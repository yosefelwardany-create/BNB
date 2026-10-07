<?php

declare(strict_types=1);

namespace App\Domain\Users\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\Invitation;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\Role;
use App\Domain\Users\Models\User;
use App\Domain\Users\Notifications\TeamInvitation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invites people into an organization.
 *
 * The invitation token is generated once, emailed, and only its SHA-256 hash
 * is stored, so the database alone cannot be used to join an organization.
 */
class InvitationService
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly AuditLogger $audit,
        private readonly AccessControl $access,
    ) {}

    /**
     * Create and send an invitation.
     *
     * @param  list<string>  $roleIds
     */
    public function invite(
        Organization $organization,
        string $email,
        array $roleIds,
        ?User $invitedBy = null,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $jobTitle = null,
        int $validDays = 14,
    ): Invitation {
        $email = mb_strtolower(trim($email));

        $alreadyMember = Membership::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->whereHas('user', fn ($q) => $q->where('email', $email))
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages([
                'email' => __('That person is already a member of this organization.'),
            ]);
        }

        $roles = Role::query()
            ->availableTo($organization)
            ->whereIn('id', $roleIds)
            ->get();

        if ($roles->isEmpty()) {
            throw ValidationException::withMessages([
                'roles' => __('Select at least one valid role for the invitation.'),
            ]);
        }

        return DB::transaction(function () use (
            $organization, $email, $roles, $invitedBy, $firstName, $lastName, $jobTitle, $validDays
        ): Invitation {
            // Supersede any outstanding invitation for the same address so a
            // resend cannot leave two usable tokens in circulation.
            Invitation::query()
                ->where('organization_id', $organization->getKey())
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $token = Str::random(48);

            $invitation = Invitation::query()->create([
                'organization_id' => $organization->getKey(),
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'job_title' => $jobTitle,
                'token_hash' => Invitation::hashToken($token),
                'invited_by_id' => $invitedBy?->getKey(),
                'expires_at' => now()->addDays($validDays),
            ]);

            $invitation->roles()->sync($roles->pluck('id')->all());

            $this->audit->record(
                action: 'invitation.sent',
                subject: $invitation,
                newValues: ['email' => $email, 'roles' => $roles->pluck('slug')->all()],
                description: sprintf('Invited %s to join %s', $email, $organization->name),
            );

            Notification::route('mail', $email)
                ->notify(new TeamInvitation($invitation, $token, $organization));

            return $invitation;
        });
    }

    /**
     * Find a pending invitation by its plaintext token.
     */
    public function findPendingByToken(string $token): ?Invitation
    {
        // Invitations are looked up before a tenant is known, so the tenant
        // scope must be lifted for this query.
        return $this->tenancy->withoutScope(
            fn (): ?Invitation => Invitation::query()
                ->with(['organization', 'roles'])
                ->where('token_hash', Invitation::hashToken($token))
                ->pending()
                ->first()
        );
    }

    /**
     * Accept an invitation: create or reuse the user, then grant the seat.
     *
     * @param  array{first_name?: string, last_name?: string|null, password?: string}  $attributes
     */
    public function accept(Invitation $invitation, array $attributes = []): Membership
    {
        return $this->tenancy->runAs($invitation->organization, function () use ($invitation, $attributes): Membership {
            $user = User::query()->where('email', $invitation->email)->first();

            if ($user === null) {
                if (empty($attributes['password'])) {
                    throw ValidationException::withMessages([
                        'password' => __('Choose a password to complete your account.'),
                    ]);
                }

                $user = User::query()->create([
                    'first_name' => $attributes['first_name'] ?? $invitation->first_name ?? 'Team',
                    'last_name' => $attributes['last_name'] ?? $invitation->last_name,
                    'email' => $invitation->email,
                    'password' => $attributes['password'],
                    'status' => 'active',
                    // The invitation was delivered to this address, so it is
                    // already proven.
                    'email_verified_at' => now(),
                ]);
            }

            $membership = Membership::query()
                ->withoutGlobalScope('organization')
                ->firstOrCreate(
                    [
                        'organization_id' => $invitation->organization_id,
                        'user_id' => $user->getKey(),
                    ],
                    [
                        'status' => 'active',
                        'job_title' => $invitation->job_title,
                        'invited_by_id' => $invitation->invited_by_id,
                        'joined_at' => now(),
                    ],
                );

            $roles = $invitation->roles;
            $membership->roles()->syncWithoutDetaching($roles->pluck('id')->all());
            $membership->default_portal = $roles->first()?->portal ?? 'admin';
            $membership->save();

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->access->forget($membership);

            $this->audit->record(
                action: 'invitation.accepted',
                subject: $invitation,
                description: sprintf('%s joined the organization', $user->email),
                organizationId: $invitation->organization_id,
            );

            return $membership->fresh(['user', 'roles']);
        });
    }

    public function revoke(Invitation $invitation): void
    {
        $invitation->forceFill(['revoked_at' => now()])->save();

        $this->audit->record(
            action: 'invitation.revoked',
            subject: $invitation,
            description: sprintf('Revoked the invitation for %s', $invitation->email),
        );
    }
}
