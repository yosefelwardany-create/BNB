<?php

declare(strict_types=1);

namespace App\Domain\Owners\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Organization\Services\OrganizationProvisioner;
use App\Domain\Owners\Models\Owner;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Users\Support\RoleRegistry;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Owners: the manager's clients.
 *
 * Two things here are more delicate than they look.
 *
 * **Portal access is a real user account, not a flag.** An owner who can log in
 * is a member of the organization holding the Owner role, restricted to their
 * own properties. Modelling it any other way would mean a second, parallel
 * authentication and authorisation path — and the one that gets less attention
 * is always the one that leaks.
 *
 * **Banking details are never overwritten blind.** An update that omits them
 * leaves them alone, because a form that round-trips masked values would
 * otherwise write the mask back over the real account number.
 */
class OwnerDirectory
{
    /** Fields that must never be overwritten with a masked or empty value. */
    private const BANKING_FIELDS = [
        'bank_account_name', 'bank_account_number', 'bank_routing_number',
        'bank_iban', 'bank_swift',
    ];

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly OrganizationProvisioner $provisioner,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Owner
    {
        $organization = $this->tenancy->organizationOrFail();

        $owner = new Owner;
        $owner->fill($this->withoutBlankBanking($attributes));
        $owner->organization_id = $organization->getKey();
        $owner->payout_currency ??= $organization->base_currency;
        $owner->created_by_id = auth()->id();
        $owner->save();

        return $owner;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Owner $owner, array $attributes): Owner
    {
        $owner->fill($this->withoutBlankBanking($attributes))->save();

        return $owner;
    }

    /**
     * Give an owner a login.
     *
     * The account is created if the person does not already have one — an
     * owner who is also a member of staff, or who owns property with two
     * managers using the platform, keeps the single account they have.
     *
     * Access is restricted to the properties they own. That restriction is
     * enforced in the data layer through the membership, not by the owner
     * portal being a separate application, so there is one authorisation story
     * rather than two.
     *
     * @return array{owner: Owner, user: User, membership: Membership, invitation_sent: bool}
     */
    public function enablePortalAccess(Owner $owner, bool $sendInvitation = true): array
    {
        if (blank($owner->email)) {
            throw new \InvalidArgumentException('An owner needs an email address before they can be given portal access.');
        }

        $organization = $this->tenancy->organizationOrFail();

        return DB::transaction(function () use ($owner, $organization, $sendInvitation): array {
            $user = $owner->user ?? User::query()->where('email', $owner->email)->first();

            $isNewAccount = $user === null;

            if ($isNewAccount) {
                $user = User::query()->create(array_filter([
                    'first_name' => $owner->first_name ?: $owner->display_name,
                    'last_name' => $owner->last_name,
                    'email' => $owner->email,
                    // Unguessable and never shown. The owner sets their own
                    // through the password-reset flow, so no credential is
                    // ever transmitted by us.
                    'password' => Str::random(64),
                    'status' => 'active',
                    // Most owners have no timezone or language recorded —
                    // nobody asks a client for either — so the organization's
                    // stands in for the timezone and the column default covers
                    // the language. Passing an explicit null would override
                    // that default rather than fall back to it, which is what
                    // array_filter is here to prevent: both columns are NOT
                    // NULL, and the owner record calls the second one
                    // `language` where the user record calls it `locale`.
                    'timezone' => $owner->timezone ?: $organization->timezone,
                    'locale' => $owner->language,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''));
            }

            $membership = $this->provisioner->attachUser(
                $organization,
                $user,
                [RoleRegistry::OWNER],
                jobTitle: 'Property owner',
            );

            // Scoped to the properties they actually own. Without this an
            // owner would see the whole portfolio, including their
            // neighbours' revenue.
            $membership->forceFill(['restricted_to_properties' => true])->save();

            $this->syncPortalProperties($owner, $membership);

            $owner->forceFill([
                'user_id' => $user->getKey(),
                'portal_enabled' => true,
            ])->save();

            $invitationSent = false;

            if ($sendInvitation && $isNewAccount) {
                Password::broker()->sendResetLink(['email' => $user->email]);
                $invitationSent = true;
            }

            $this->audit->record(
                action: 'owner.portal_enabled',
                subject: $owner,
                newValues: ['user_id' => $user->getKey(), 'new_account' => $isNewAccount],
                description: sprintf('Portal access granted to %s', $owner->display_name),
            );

            return [
                'owner' => $owner->fresh(),
                'user' => $user,
                'membership' => $membership,
                'invitation_sent' => $invitationSent,
            ];
        });
    }

    /**
     * Withdraw an owner's login.
     *
     * The membership is suspended rather than deleted, and the user account is
     * left alone: it may be their only account, and the audit trail of what
     * they did while they had access must still resolve to a person.
     */
    public function disablePortalAccess(Owner $owner): Owner
    {
        $membership = $this->membershipFor($owner);

        $membership?->forceFill(['status' => 'suspended'])->save();

        $owner->forceFill(['portal_enabled' => false])->save();

        $this->audit->record(
            action: 'owner.portal_disabled',
            subject: $owner,
            description: sprintf('Portal access withdrawn from %s', $owner->display_name),
        );

        return $owner->fresh();
    }

    /**
     * Keep an owner's portal visibility in step with what they own.
     *
     * Called when access is granted and whenever their holdings change: an
     * owner who buys a second flat should see it without anyone remembering to
     * update a permission, and one who sells should stop seeing it.
     */
    public function syncPortalProperties(Owner $owner, ?Membership $membership = null): void
    {
        $membership ??= $this->membershipFor($owner);

        if ($membership === null) {
            return;
        }

        $propertyIds = $owner->ownerships()
            ->pluck('property_id')
            ->unique()
            ->values()
            ->all();

        $membership->properties()->sync($propertyIds);

        app(AccessControl::class)->forget($membership);
    }

    /**
     * Owners whose statements are due to be produced for a period.
     *
     * The frequency and day live on the owner because they are negotiated per
     * client: a company with an accounts department wants the 1st, an
     * individual would rather be paid the day after the guest leaves.
     *
     * @return Collection<int, Owner>
     */
    public function dueForStatement(CarbonImmutable $on): Collection
    {
        return Owner::query()
            ->active()
            ->get()
            ->filter(function (Owner $owner) use ($on): bool {
                $day = (int) ($owner->statement_day ?: 1);

                return match ($owner->statement_frequency) {
                    'weekly' => (int) $on->dayOfWeekIso === max(1, min(7, $day)),
                    'fortnightly' => (int) $on->day === $day || (int) $on->day === $day + 14,
                    'quarterly' => in_array((int) $on->month, [1, 4, 7, 10], true) && (int) $on->day === $day,
                    'annually' => (int) $on->month === 1 && (int) $on->day === $day,
                    // Monthly is the default, and a day beyond the length of a
                    // short month falls on its last day rather than being
                    // skipped entirely — February must not swallow a statement.
                    default => (int) $on->day === min($day, (int) $on->daysInMonth),
                };
            })
            ->values();
    }

    /**
     * Drop banking fields the caller left blank or sent back masked.
     *
     * A settings form that renders `••••1234` and posts the whole record back
     * would otherwise store those bullets as the account number, and nobody
     * finds out until a payout fails.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function withoutBlankBanking(array $attributes): array
    {
        foreach (self::BANKING_FIELDS as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $value = $attributes[$field];

            if ($value === null || $value === '' || str_contains((string) $value, '•')) {
                unset($attributes[$field]);
            }
        }

        return $attributes;
    }

    private function membershipFor(Owner $owner): ?Membership
    {
        if ($owner->user_id === null) {
            return null;
        }

        return Membership::query()
            ->where('user_id', $owner->user_id)
            ->where('organization_id', $owner->organization_id)
            ->first();
    }
}
