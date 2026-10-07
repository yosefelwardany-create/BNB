<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Models\Organization;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * What the platform operator can do to a tenant.
 *
 * Every method here is an act of power over somebody else's business, so every
 * one of them records a reason and is audited. Nothing here deletes a tenant's
 * data: the strongest action available is suspension, which stops access and
 * leaves every record intact.
 *
 * There is deliberately no "delete organization". A property-management company
 * that leaves still has reservations, statements and ledger entries that its
 * former owners and its tax authority may need for years, and a single call
 * that could destroy all of it is not a feature — it is an outage waiting for
 * somebody to mistype an id. Cancellation ends access; export and erasure are a
 * separate, deliberate process.
 */
class TenantAdministration
{
    /**
     * Stop a tenant operating, and say why.
     *
     * Signing out is part of suspending: a session that keeps working for an
     * hour after suspension makes the suspension advisory. Their tokens are
     * revoked, and their data is untouched.
     */
    public function suspend(Organization $organization, string $reason, User $actor): Organization
    {
        if ($organization->isSuspended()) {
            return $organization;
        }

        return DB::transaction(function () use ($organization, $reason, $actor): Organization {
            $previous = $organization->status;

            $organization->forceFill([
                'status' => OrganizationStatus::Suspended->value,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
            ])->save();

            $revoked = $this->revokeTokensFor($organization);

            $this->record(
                $organization,
                $actor,
                'organization.suspended',
                sprintf(
                    'Suspended (was %s): %s. %d session(s) ended.',
                    $previous->value,
                    $reason,
                    $revoked,
                ),
                ['status' => $previous->value],
                ['status' => OrganizationStatus::Suspended->value, 'suspension_reason' => $reason],
            );

            return $organization->fresh();
        });
    }

    /**
     * Let a suspended client account back in.
     */
    public function reinstate(Organization $organization, string $reason, User $actor): Organization
    {
        if (! $organization->isSuspended()) {
            return $organization;
        }

        $target = OrganizationStatus::Active;

        $organization->forceFill([
            'status' => $target->value,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        $this->record(
            $organization,
            $actor,
            'organization.reinstated',
            sprintf('Reinstated as %s: %s', $target->value, $reason),
            ['status' => OrganizationStatus::Suspended->value],
            ['status' => $target->value],
        );

        return $organization->fresh();
    }

    /**
     * Mark a tenant as gone.
     *
     * Cancelled rather than deleted, and the distinction is the point: access
     * ends, tokens are revoked, and every record survives for whoever needs it
     * later — the former owners, an auditor, a tax authority.
     */
    public function cancel(Organization $organization, string $reason, User $actor): Organization
    {
        $previous = $organization->status;

        $organization->forceFill([
            'status' => OrganizationStatus::Cancelled->value,
            'suspension_reason' => $reason,
            'suspended_at' => now(),
        ])->save();

        $revoked = $this->revokeTokensFor($organization);

        $this->record(
            $organization,
            $actor,
            'organization.cancelled',
            sprintf('Cancelled (was %s): %s. %d session(s) ended. No data was deleted.',
                $previous->value, $reason, $revoked),
            ['status' => $previous->value],
            ['status' => OrganizationStatus::Cancelled->value],
        );

        return $organization->fresh();
    }

    /**
     * End every API session belonging to this organization's members.
     *
     * Scoped to members of this organization only. A user who works for two
     * companies and has one of them suspended keeps their access to the other,
     * which is why this cannot simply delete every token a user holds.
     */
    private function revokeTokensFor(Organization $organization): int
    {
        $userIds = DB::table('memberships')
            ->where('organization_id', $organization->getKey())
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return 0;
        }

        // Users who are only in this organization lose their tokens. Users with
        // a seat elsewhere keep theirs: the tenant resolver already refuses a
        // suspended organization, so their remaining access is to the other
        // company, which is correct.
        $soleMembers = DB::table('memberships')
            ->whereIn('user_id', $userIds)
            ->selectRaw('user_id, count(*) as seats')
            ->groupBy('user_id')
            ->having(DB::raw('count(*)'), '=', 1)
            ->pluck('user_id');

        if ($soleMembers->isEmpty()) {
            return 0;
        }

        return PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->whereIn('tokenable_id', $soleMembers)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function record(
        Organization $organization,
        User $actor,
        string $action,
        string $description,
        array $old = [],
        array $new = [],
    ): void {
        // The tenant is named explicitly. The audit logger normally derives it
        // from the subject's `organization_id`, and an Organization has no such
        // column — it *is* the tenant — so without this the row would be
        // skipped as unattributable and the most important action on the
        // platform would go unrecorded.
        app(AuditLogger::class)->record(
            action: $action,
            subject: $organization,
            oldValues: $old,
            newValues: $new,
            description: $description,
            context: ['platform_actor_id' => $actor->getKey()],
            organizationId: $organization->getKey(),
        );

        // And in the platform's own trail, so an operator can read what the
        // platform did without querying across every tenant. Deliberately both:
        // the customer's copy is theirs to read, this one is the operator's, and
        // neither should depend on the other existing.
        app(PlatformAuditLogger::class)->record(
            action: $action,
            actor: $actor,
            organization: $organization,
            subject: $organization,
            description: $description,
            context: ['old' => $old, 'new' => $new],
        );
    }
}
