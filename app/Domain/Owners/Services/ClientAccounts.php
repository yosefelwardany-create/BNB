<?php

declare(strict_types=1);

namespace App\Domain\Owners\Services;

use App\Domain\Accounting\Services\ChartOfAccountsInstaller;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Services\OrganizationProvisioner;
use App\Domain\Owners\Models\ManagementAgreement;
use App\Domain\Owners\Models\Owner;
use App\Domain\Owners\Models\PropertyOwnership;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Services\CancellationPolicyInstaller;
use App\Domain\Users\Models\Membership;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use App\Domain\Users\Support\RoleRegistry;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Client accounts: what a managed-service client is, in data.
 *
 * A client is an organization of their own, which keeps every existing record
 * (Hostex connection, properties, bookings, currency) exactly where it is. On
 * top of that, four things make it a *client* account rather than a tenant:
 *
 *  1. An **account-holder owner record** — the one `Owner` that stands for the
 *     client. The portal resolves to it when a login has no owner of its own.
 *  2. A **blanket management agreement** at the platform's commission rate,
 *     with every flag set explicitly so that "10% of revenue" means 10% of
 *     accommodation revenue and not 10% of what is left after Airbnb's fee.
 *  3. An **ownership row** for every property nobody else owns, so revenue is
 *     attributed and the portal shows the property.
 *  4. **Logins holding the client role**, which carries no staff permission.
 *
 * Everything here is additive and idempotent. It never edits an ownership
 * share, an agreement or a membership that already exists: a property owned by
 * somebody else keeps its owner, an agreement already in force keeps its terms,
 * and a staff account keeps its role until a person converts it deliberately
 * with {@see convertMembership()}.
 */
class ClientAccounts
{
    /** The management commission, as a percentage of commissionable revenue. */
    public const DEFAULT_COMMISSION_RATE = 10.0;

    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly OrganizationProvisioner $provisioner,
        private readonly OwnerDirectory $owners,
        private readonly ChartOfAccountsInstaller $chartOfAccounts,
        private readonly CancellationPolicyInstaller $cancellationPolicies,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create a client account end to end.
     *
     * @param  array{name: string, base_currency?: string, timezone?: string, locale?: string, country_code?: string, legal_name?: string}  $organizationAttributes
     * @param  array{first_name: string, last_name?: ?string, email: string, password?: string, phone?: string, timezone?: string}  $holderAttributes
     * @return array{organization: Organization, owner: Owner, user: User, membership: Membership, invitation_sent: bool}
     */
    public function provision(array $organizationAttributes, array $holderAttributes, bool $sendInvitation = true): array
    {
        return DB::transaction(function () use ($organizationAttributes, $holderAttributes, $sendInvitation): array {
            $organization = $this->provisioner->createOrganization($organizationAttributes + [
                // A client account is simply active. There is nothing to trial.
                'status' => 'active',
                'trial_ends_at' => null,
                'contact_email' => $holderAttributes['email'],
            ]);

            return $this->tenancy->runAs($organization, function () use ($organization, $holderAttributes, $sendInvitation): array {
                $this->chartOfAccounts->install($organization);
                $this->cancellationPolicies->install($organization);

                $owner = $this->ensureAccountHolder($organization, $holderAttributes);
                $this->ensureAgreement($organization, $owner);

                if (! empty($holderAttributes['password'])) {
                    // They chose a password (public sign-up). The account is
                    // created here so that the portal-access step below finds
                    // it by email and attaches the client role to it, rather
                    // than inventing a second, random credential.
                    User::query()->create(array_filter([
                        'first_name' => $holderAttributes['first_name'],
                        'last_name' => $holderAttributes['last_name'] ?? null,
                        'email' => mb_strtolower(trim($holderAttributes['email'])),
                        'password' => $holderAttributes['password'],
                        'phone' => $holderAttributes['phone'] ?? null,
                        'timezone' => $holderAttributes['timezone'] ?? $organization->timezone,
                        'status' => 'active',
                    ], static fn (mixed $value): bool => $value !== null && $value !== ''));

                    $sendInvitation = false;
                }

                $access = $this->owners->enablePortalAccess($owner, $sendInvitation);

                return [
                    'organization' => $organization,
                    'owner' => $access['owner'],
                    'user' => $access['user'],
                    'membership' => $access['membership'],
                    'invitation_sent' => $access['invitation_sent'],
                ];
            });
        });
    }

    /**
     * The organization's account-holder owner record, created if absent.
     *
     * Existing owner records are never promoted: a client organization may hold
     * real third-party owners with their own shares, and silently making one
     * of them "the client" would hand them a portal view of properties they do
     * not own. The holder is always a record of its own.
     *
     * @param  array{first_name?: string, last_name?: ?string, email?: string, phone?: string, timezone?: string}  $attributes
     */
    public function ensureAccountHolder(Organization $organization, array $attributes = []): Owner
    {
        return $this->tenancy->runAs($organization, function () use ($organization, $attributes): Owner {
            $existing = Owner::query()->accountHolder()->first();

            if ($existing !== null) {
                return $existing;
            }

            $email = $attributes['email']
                ?? $organization->contact_email
                ?? $this->primaryContactEmail($organization);

            $owner = new Owner;
            $owner->fill([
                'type' => 'company',
                'company_name' => $organization->legal_name ?: $organization->name,
                'display_name' => $organization->name,
                'first_name' => $attributes['first_name'] ?? null,
                'last_name' => $attributes['last_name'] ?? null,
                'email' => $email,
                'phone' => $attributes['phone'] ?? $organization->contact_phone,
                'country_code' => $organization->country_code,
                'timezone' => $attributes['timezone'] ?? $organization->timezone,
                // The currency the properties actually earn in, when they all
                // earn in one. A statement refuses to mix currencies, so an
                // account whose base currency differs from its properties'
                // (a USD account letting a CAD flat) would otherwise have
                // every statement refused.
                'payout_currency' => $this->singlePropertyCurrency() ?? $organization->base_currency,
                'is_account_holder' => true,
                'notes' => 'The client account holder. Created by the managed-service provisioning.',
            ]);
            $owner->organization_id = $organization->getKey();
            $owner->save();

            return $owner;
        });
    }

    /**
     * The blanket agreement for the account holder, created if absent.
     *
     * Never edited once it exists: changing a client's terms is a decision
     * somebody makes on the agreement itself, dated, not a side effect of a
     * deploy. The flags are set explicitly rather than inherited from the
     * model's defaults, because the defaults deduct the channel's commission
     * first and this agreement charges on gross accommodation revenue.
     */
    public function ensureAgreement(Organization $organization, Owner $owner, ?CarbonImmutable $effectiveFrom = null): ManagementAgreement
    {
        return $this->tenancy->runAs($organization, function () use ($organization, $owner, $effectiveFrom): ManagementAgreement {
            $existing = ManagementAgreement::query()
                ->where('owner_id', $owner->getKey())
                ->whereNull('property_id')
                ->where('status', 'active')
                ->whereNull('ends_on')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $agreement = new ManagementAgreement;
            $agreement->fill([
                'owner_id' => $owner->getKey(),
                'property_id' => null,
                'name' => 'Management agreement',
                'commission_model' => ManagementAgreement::PERCENT_OF_REVENUE,
                'commission_rate' => self::DEFAULT_COMMISSION_RATE,
                'currency' => $owner->payout_currency ?: $organization->base_currency,
                // 10% of accommodation revenue, before the channel's and the
                // processor's cuts, excluding cleaning fees and taxes.
                'commission_on_accommodation' => true,
                'commission_on_fees' => false,
                'commission_on_taxes' => false,
                'deduct_channel_commission_first' => false,
                'deduct_payment_fees_first' => false,
                'owner_pays_cleaning' => false,
                'owner_pays_maintenance' => true,
                'owner_pays_supplies' => true,
                'starts_on' => ($effectiveFrom ?? CarbonImmutable::parse($organization->created_at ?? now()))->toDateString(),
                'ends_on' => null,
                'status' => 'active',
                'terms' => 'A management commission of 10% of commissionable property revenue. '
                    .'Commissionable revenue is the accommodation revenue of each stay night, '
                    .'excluding cleaning fees, taxes and extra fees, before channel commission.',
            ]);
            $agreement->organization_id = $organization->getKey();
            $agreement->save();

            return $agreement;
        });
    }

    /**
     * Give the account holder every property nobody owns.
     *
     * A property that already carries any ownership row — in force, ended or
     * future — is left alone: those shares were entered deliberately and a
     * backfill must never rewrite who owns what.
     *
     * @return int the number of ownership rows created
     */
    public function ensureOwnerships(Organization $organization): int
    {
        return $this->tenancy->runAs($organization, function (): int {
            $holder = Owner::query()->accountHolder()->first();

            if ($holder === null) {
                return 0;
            }

            $created = 0;

            Property::query()
                ->whereDoesntHave('ownerships')
                ->orderBy('created_at')
                ->each(function (Property $property) use ($holder, &$created): void {
                    if ($this->attach($property, $holder)) {
                        $created++;
                    }
                });

            if ($created > 0) {
                $this->owners->syncPortalProperties($holder->fresh());
            }

            return $created;
        });
    }

    /**
     * One property, as it is created.
     *
     * Called by the listener on PropertyCreated. Tolerates an organization with
     * no account holder yet (a legacy tenant before provisioning has run): the
     * backfill picks the property up later.
     */
    public function attachProperty(Property $property): ?PropertyOwnership
    {
        $organization = $this->tenancy->withoutScope(
            fn (): ?Organization => Organization::query()->find($property->organization_id),
        );

        if ($organization === null) {
            return null;
        }

        return $this->tenancy->runAs($organization, function () use ($property): ?PropertyOwnership {
            $holder = Owner::query()->accountHolder()->first();

            if ($holder === null) {
                Log::info('Property created in an organization with no client account holder; ownership left for clients:provision.', [
                    'property_id' => $property->getKey(),
                    'organization_id' => $property->organization_id,
                ]);

                return null;
            }

            $ownership = $this->attach($property, $holder);

            if ($ownership !== null) {
                $this->owners->syncPortalProperties($holder->fresh());
            }

            return $ownership;
        });
    }

    /**
     * Convert one existing membership into a client login.
     *
     * Deliberately one at a time and never on a timer. The person running it
     * has looked at the account and decided this login belongs to the client,
     * not to the management company's own staff. Refused outright until a
     * platform owner exists, because the conversion removes administrative
     * access and somebody has to still have it.
     *
     * @return array{membership: Membership, previous_roles: list<string>}
     */
    public function convertMembership(Organization $organization, User $user, ?string $reason = null): array
    {
        if (! User::query()->where('is_platform_admin', true)->exists()) {
            throw new \RuntimeException(
                'Refusing to convert a membership before a platform owner exists. Run platform:grant-admin first.'
            );
        }

        if ($user->isPlatformAdmin()) {
            throw new \RuntimeException('A platform owner cannot be converted into a client.');
        }

        return $this->tenancy->runAs($organization, function () use ($organization, $user, $reason): array {
            $membership = Membership::query()
                ->with('roles')
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->first();

            if ($membership === null) {
                throw new \RuntimeException(sprintf('%s has no active membership in %s.', $user->email, $organization->name));
            }

            $previousRoles = $membership->roles->pluck('slug')->values()->all();

            $holder = $this->ensureAccountHolder($organization);
            $this->ensureAgreement($organization, $holder);

            return DB::transaction(function () use ($organization, $user, $membership, $holder, $previousRoles, $reason): array {
                $this->provisioner->attachUser($organization, $user, [RoleRegistry::CLIENT], 'Client');

                // Exactly the client role: syncWithoutDetaching above added it,
                // and the staff roles come off here.
                $clientRole = $membership->roles()->getRelated()->newQuery()
                    ->availableTo($organization)
                    ->where('slug', RoleRegistry::CLIENT)
                    ->firstOrFail();

                $membership->roles()->sync([$clientRole->getKey()]);
                $membership->forceFill([
                    'default_portal' => 'owner',
                    'restricted_to_properties' => true,
                    'job_title' => 'Client',
                ])->save();

                if ($holder->user_id === null) {
                    $holder->forceFill(['user_id' => $user->getKey(), 'portal_enabled' => true])->save();
                }

                $this->owners->syncPortalProperties($holder->fresh(), $membership->fresh());
                $this->access->forget($membership);
                $this->access->flushOrganization($organization);

                $this->audit->record(
                    action: 'membership.converted_to_client',
                    subject: $membership,
                    oldValues: ['roles' => $previousRoles],
                    newValues: ['roles' => [RoleRegistry::CLIENT]],
                    description: sprintf('%s converted to a client login%s', $user->email, $reason ? ': '.$reason : '.'),
                );

                return ['membership' => $membership->fresh(['roles']), 'previous_roles' => $previousRoles];
            });
        });
    }

    /**
     * Make one login the account's only login, and that login a client.
     *
     * The managed service's rule: a client account has exactly one login,
     * the client's own, and it reads. Everything else in the account is done
     * by the platform owner, who holds no membership in it. This converts the
     * chosen membership to the client role, links it to the account holder,
     * and suspends every other active membership in the account (platform
     * owners excepted, though they hold none). Suspended, never deleted: the
     * audit trail of what those logins did must still resolve to a person,
     * and a mistaken suspension is undone by reinstating the membership.
     *
     * Only ever run because the platform owner chose to, from Accounts or the
     * command line. Nothing calls it on boot.
     *
     * @return array{membership: Membership, suspended: list<string>}
     */
    public function makeSoleClientLogin(Organization $organization, string $membershipId, ?string $reason = null): array
    {
        $chosen = $this->tenancy->runAs($organization, fn (): ?Membership => Membership::query()
            ->with('user')
            ->whereKey($membershipId)
            ->first());

        if ($chosen === null || $chosen->user === null) {
            throw new \RuntimeException('That login does not belong to this account.');
        }

        if ($chosen->status->value !== 'active') {
            throw new \RuntimeException('That login is not active. Reinstate it before making it the client login.');
        }

        $user = $chosen->user;
        $converted = $this->convertMembership($organization, $user, $reason);

        return $this->tenancy->runAs($organization, function () use ($organization, $user, $converted, $reason): array {
            return DB::transaction(function () use ($organization, $user, $converted, $reason): array {
                $holder = $this->ensureAccountHolder($organization);

                // The holder reads as this login from now on, whoever it was
                // linked to before.
                if ($holder->user_id !== $user->getKey()) {
                    $holder->forceFill(['user_id' => $user->getKey(), 'portal_enabled' => true])->save();
                }

                $others = Membership::query()
                    ->with('user')
                    ->where('status', 'active')
                    ->whereKeyNot($converted['membership']->getKey())
                    ->get()
                    ->reject(fn (Membership $m): bool => $m->user?->isPlatformAdmin() ?? false);

                $suspended = [];

                foreach ($others as $membership) {
                    $membership->forceFill(['status' => 'suspended'])->save();
                    $this->access->forget($membership);

                    // An owner record that pointed at a login which no longer
                    // works should not claim portal access.
                    Owner::query()
                        ->where('user_id', $membership->user_id)
                        ->update(['portal_enabled' => false]);

                    $suspended[] = (string) $membership->user?->email;

                    $this->audit->record(
                        action: 'membership.suspended_for_single_login',
                        subject: $membership,
                        oldValues: ['status' => 'active'],
                        newValues: ['status' => 'suspended'],
                        description: sprintf(
                            '%s suspended: %s is now the only login for this account%s',
                            $membership->user?->email ?? 'A login',
                            $user->email,
                            $reason ? ' ('.$reason.')' : '.',
                        ),
                    );
                }

                $this->owners->syncPortalProperties($holder->fresh(), $converted['membership']);
                $this->access->flushOrganization($organization);

                return ['membership' => $converted['membership']->fresh(['roles']), 'suspended' => $suspended];
            });
        });
    }

    /**
     * The account's client login, if it has one.
     */
    public function clientLogin(Organization $organization): ?Membership
    {
        return $this->tenancy->runAs($organization, fn (): ?Membership => Membership::query()
            ->with('user')
            ->where('status', 'active')
            ->where('default_portal', 'owner')
            ->whereHas('roles', fn ($q) => $q->where('slug', RoleRegistry::CLIENT))
            ->orderBy('created_at')
            ->first());
    }

    /**
     * The owner record a portal login reads as.
     *
     * Their own record first. Failing that, when their membership holds the
     * client role, the organization's account holder — a second client login
     * (a partner, an accountant) reads the same portfolio. Staff get nothing
     * here; they have the management workspace.
     */
    public function portalOwnerFor(User $user, Organization $organization): ?Owner
    {
        return $this->tenancy->runAs($organization, function () use ($user): ?Owner {
            $own = Owner::query()->where('user_id', $user->getKey())->first();

            if ($own !== null) {
                return $own;
            }

            $isClient = Membership::query()
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->whereHas('roles', fn ($q) => $q->where('slug', RoleRegistry::CLIENT))
                ->exists();

            return $isClient ? Owner::query()->accountHolder()->first() : null;
        });
    }

    /**
     * The state of a client account's onboarding, for the owner's screens.
     *
     * @return array<string, mixed>
     */
    public function describe(Organization $organization): array
    {
        return $this->tenancy->runAs($organization, function () use ($organization): array {
            $holder = Owner::query()->accountHolder()->first();

            $agreement = $holder === null ? null : ManagementAgreement::query()
                ->where('owner_id', $holder->getKey())
                ->whereNull('property_id')
                ->where('status', 'active')
                ->whereNull('ends_on')
                ->first();

            $memberships = Membership::query()
                ->with(['roles', 'user'])
                ->where('status', 'active')
                ->get();

            $clientLogins = $memberships->filter(
                fn (Membership $m): bool => $m->roles->contains('slug', RoleRegistry::CLIENT),
            );

            $staffLogins = $memberships->filter(
                fn (Membership $m): bool => ! $m->roles->contains('slug', RoleRegistry::CLIENT)
                    && ! ($m->user?->isPlatformAdmin() ?? false),
            );

            return [
                'account_holder' => $holder === null ? null : [
                    'id' => $holder->getKey(),
                    'display_name' => $holder->display_name,
                    'email' => $holder->email,
                    'has_login' => $holder->user_id !== null,
                ],
                'agreement' => $agreement === null ? null : [
                    'id' => $agreement->getKey(),
                    'commission_model' => $agreement->commission_model,
                    'commission_rate' => (float) $agreement->commission_rate,
                    'starts_on' => $agreement->starts_on?->toDateString(),
                    'deduct_channel_commission_first' => (bool) $agreement->deduct_channel_commission_first,
                ],
                'properties_without_ownership' => Property::query()->whereDoesntHave('ownerships')->count(),
                'properties_with_other_owners' => $holder === null ? 0 : Property::query()
                    ->whereHas('ownerships', fn ($q) => $q->where('owner_id', '!=', $holder->getKey()))
                    ->count(),
                'client_logins' => $clientLogins->count(),
                // Memberships still holding staff roles, for the owner to decide
                // about one by one. Never converted automatically.
                'staff_logins' => $staffLogins->map(fn (Membership $m): array => [
                    'membership_id' => $m->getKey(),
                    'email' => $m->user?->email,
                    'roles' => $m->roles->pluck('slug')->values()->all(),
                ])->values()->all(),
                'organization_name' => $organization->name,
            ];
        });
    }

    private function attach(Property $property, Owner $holder): ?PropertyOwnership
    {
        // Any share at all, in force or not, means somebody entered ownership
        // deliberately. Not ours to change.
        if (PropertyOwnership::query()->where('property_id', $property->getKey())->exists()) {
            return null;
        }

        $ownership = new PropertyOwnership;
        $ownership->fill([
            'property_id' => $property->getKey(),
            'owner_id' => $holder->getKey(),
            'ownership_percentage' => 100,
            'is_primary' => true,
            // From the day the property existed here, so every imported night
            // is attributed. Whether a night is commissionable is the
            // agreement's effective date to decide, not this one.
            'starts_on' => ($property->created_at ?? now())->toDateString(),
            'ends_on' => null,
            'notes' => 'Attributed to the client account holder by the managed-service provisioning.',
        ]);
        $ownership->organization_id = $property->organization_id;
        $ownership->save();

        return $ownership;
    }

    /**
     * The one currency every property in the current account is priced in, or
     * null when there are none or more than one.
     */
    private function singlePropertyCurrency(): ?string
    {
        $currencies = Property::query()
            ->whereNotNull('currency')
            ->distinct()
            ->pluck('currency');

        return $currencies->count() === 1 ? (string) $currencies->first() : null;
    }

    /**
     * The email of the earliest active member who is not a platform owner.
     */
    private function primaryContactEmail(Organization $organization): ?string
    {
        $membership = Membership::query()
            ->with('user')
            ->where('status', 'active')
            ->orderBy('created_at')
            ->get()
            ->first(fn (Membership $m): bool => $m->user !== null && ! $m->user->isPlatformAdmin());

        return $membership?->user?->email;
    }
}
