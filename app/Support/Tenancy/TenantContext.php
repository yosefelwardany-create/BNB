<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Domain\Organization\Models\Organization;
use Closure;

/**
 * Holds the organization (tenant) the current request, job or command is
 * acting on.
 *
 * This object is the single source of truth for tenant scoping. Models using
 * the {@see \App\Support\Concerns\BelongsToOrganization} trait read from it on
 * every query, so setting it incorrectly is a security incident — it is
 * therefore only ever set by the tenancy middleware, by queued jobs that carry
 * an explicit organization id, and by console commands that state which tenant
 * they operate on.
 */
final class TenantContext
{
    private ?Organization $organization = null;

    /**
     * When true, tenant scoping is suspended for the current closure. Used by
     * the platform (super admin) surface, migrations and cross-tenant
     * maintenance jobs.
     */
    private bool $suspended = false;

    public function set(Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function clear(): void
    {
        $this->organization = null;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?string
    {
        return $this->organization?->getKey();
    }

    public function hasTenant(): bool
    {
        return $this->organization !== null;
    }

    /**
     * The tenant that must exist. Throws rather than silently returning data
     * from every organization.
     */
    public function organizationOrFail(): Organization
    {
        if ($this->organization === null) {
            throw new TenantNotResolvedException(
                'No organization is bound to the current execution context.'
            );
        }

        return $this->organization;
    }

    public function isSuspended(): bool
    {
        return $this->suspended;
    }

    /**
     * Whether queries should currently be constrained to a tenant.
     */
    public function shouldScope(): bool
    {
        return ! $this->suspended && $this->organization !== null;
    }

    /**
     * Run a callback with tenant scoping disabled.
     *
     * Reserved for platform-level operations. Anything reachable from an
     * authenticated organization user must not use this.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutScope(Closure $callback): mixed
    {
        $previous = $this->suspended;
        $this->suspended = true;

        try {
            return $callback();
        } finally {
            $this->suspended = $previous;
        }
    }

    /**
     * Run a callback as a specific tenant, restoring the previous context
     * afterwards. Queued jobs use this to rebuild their tenant.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runAs(Organization $organization, Closure $callback): mixed
    {
        $previousOrganization = $this->organization;
        $previousSuspended = $this->suspended;

        $this->organization = $organization;
        $this->suspended = false;

        try {
            return $callback();
        } finally {
            $this->organization = $previousOrganization;
            $this->suspended = $previousSuspended;
        }
    }
}
