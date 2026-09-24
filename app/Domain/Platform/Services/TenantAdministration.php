<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Organization\Enums\OrganizationStatus;
use App\Domain\Organization\Models\Organization;
use App\Domain\Platform\Models\Plan;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
     * Let a suspended tenant back in.
     *
     * Returns to trial or active depending on whether their trial is still
     * running, rather than always to active — reinstating somebody who never
     * finished a trial should not silently hand them a paid plan.
     */
    public function reinstate(Organization $organization, string $reason, User $actor): Organization
    {
        if (! $organization->isSuspended()) {
            return $organization;
        }

        $target = $organization->trial_ends_at !== null && $organization->trial_ends_at->isFuture()
            ? OrganizationStatus::Trial
            : OrganizationStatus::Active;

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
     * Move a tenant onto a plan.
     *
     * A downgrade that puts them over a cap is allowed and is reported rather
     * than refused. Refusing would leave the platform operator unable to
     * complete a commercial decision the customer has already agreed to, and
     * deleting the excess to make it fit is never the answer. They keep what
     * they have and cannot add more; the returned breach list is what the
     * console shows the operator so the conversation happens now rather than at
     * the customer's next click.
     *
     * @return array{organization: Organization, breaches: array<string, array{used: int, limit: int}>}
     */
    public function changePlan(
        Organization $organization,
        ?Plan $plan,
        User $actor,
        ?string $reason = null,
    ): array {
        $previous = $organization->plan;

        $organization->forceFill(['plan_id' => $plan?->getKey()])->save();
        $organization->setRelation('plan', $plan);

        $breaches = [];

        foreach (app(PlanEnforcement::class)->usage($organization->fresh()) as $key => $row) {
            if ($row['limit'] !== null && $row['used'] > $row['limit']) {
                $breaches[$key] = ['used' => $row['used'], 'limit' => $row['limit']];
            }
        }

        $this->record(
            $organization,
            $actor,
            'organization.plan_changed',
            sprintf(
                'Plan changed from %s to %s%s%s',
                $previous?->name ?? 'none',
                $plan?->name ?? 'none',
                $reason === null ? '' : ': '.$reason,
                $breaches === [] ? '' : sprintf(' (%d limit(s) now exceeded)', count($breaches)),
            ),
            ['plan_id' => $previous?->getKey()],
            ['plan_id' => $plan?->getKey()],
        );

        return ['organization' => $organization->fresh('plan'), 'breaches' => $breaches];
    }

    /**
     * Move a trial's end date.
     *
     * Shortening is allowed, because a trial granted by mistake has to be
     * revocable — but it cannot be set in the past, which would be a
     * suspension wearing a trial's clothes and would not revoke their tokens.
     */
    public function setTrialEnd(
        Organization $organization,
        ?CarbonImmutable $endsAt,
        User $actor,
        ?string $reason = null,
    ): Organization {
        if ($endsAt !== null && $endsAt->isPast()) {
            throw new HttpException(
                422,
                'A trial cannot be set to end in the past. Suspend the organization instead.',
            );
        }

        $previous = $organization->trial_ends_at;

        $organization->forceFill(['trial_ends_at' => $endsAt])->save();

        $this->record(
            $organization,
            $actor,
            'organization.trial_changed',
            sprintf(
                'Trial end moved from %s to %s%s',
                $previous?->toDateString() ?? 'none',
                $endsAt?->toDateString() ?? 'none',
                $reason === null ? '' : ': '.$reason,
            ),
            ['trial_ends_at' => $previous?->toIso8601String()],
            ['trial_ends_at' => $endsAt?->toIso8601String()],
        );

        return $organization->fresh();
    }

    /**
     * Override a cap or a feature for one tenant.
     *
     * Exists so a negotiated exception does not require inventing a plan nobody
     * else is on. Keys are validated against the registry by the caller; an
     * override of null lifts the plan's cap entirely.
     *
     * @param  array<string, int|null>|null  $limits
     * @param  array<string, bool>|null  $features
     */
    public function setOverrides(
        Organization $organization,
        ?array $limits,
        ?array $features,
        User $actor,
        ?string $reason = null,
    ): Organization {
        $previous = [
            'limit_overrides' => $organization->limit_overrides,
            'feature_overrides' => $organization->feature_overrides,
        ];

        if ($limits !== null) {
            $organization->limit_overrides = $limits === [] ? null : $limits;
        }

        if ($features !== null) {
            $organization->feature_overrides = $features === [] ? null : $features;
        }

        $organization->save();

        $this->record(
            $organization,
            $actor,
            'organization.overrides_changed',
            $reason ?? 'Per-organization limits or features changed.',
            $previous,
            [
                'limit_overrides' => $organization->limit_overrides,
                'feature_overrides' => $organization->feature_overrides,
            ],
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
