<?php

declare(strict_types=1);

namespace App\Support\Concerns;

use App\Domain\Organization\Models\Organization;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantNotResolvedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applies tenant isolation to a model.
 *
 * Two things happen automatically:
 *
 *  1. A global query scope constrains every read to the organization bound to
 *     the current execution context.
 *  2. New records inherit that organization, and any attempt to write a record
 *     belonging to a different organization is rejected.
 *
 * Isolation is enforced here — in the data layer — precisely so that no
 * controller, service or report can forget to filter by tenant.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder): void {
            $tenancy = app(TenantContext::class);

            if (! $tenancy->shouldScope()) {
                return;
            }

            $builder->where(
                $builder->getModel()->qualifyColumn('organization_id'),
                $tenancy->id(),
            );
        });

        static::creating(function ($model): void {
            $tenancy = app(TenantContext::class);

            if ($model->getAttribute('organization_id') === null) {
                if (! $tenancy->hasTenant()) {
                    throw new TenantNotResolvedException(sprintf(
                        'Cannot create [%s] without an organization: no tenant is bound to the current context.',
                        $model::class,
                    ));
                }

                $model->setAttribute('organization_id', $tenancy->id());
            }
        });

        static::saving(function ($model): void {
            $tenancy = app(TenantContext::class);

            if (! $tenancy->shouldScope()) {
                return;
            }

            $organizationId = $model->getAttribute('organization_id');

            if ($organizationId !== null && $organizationId !== $tenancy->id()) {
                throw new CrossTenantWriteException(sprintf(
                    'Refusing to write [%s] belonging to organization [%s] while acting as [%s].',
                    $model::class,
                    $organizationId,
                    (string) $tenancy->id(),
                ));
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Escape the tenant scope for a single query.
     *
     * Only platform-level code (super admin tooling, cross-tenant maintenance)
     * may use this.
     */
    public function scopeAcrossOrganizations(Builder $query): Builder
    {
        return $query->withoutGlobalScope('organization');
    }

    /**
     * Constrain a query to a specific organization irrespective of context.
     */
    public function scopeForOrganization(Builder $query, Organization|string $organization): Builder
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $query->withoutGlobalScope('organization')
            ->where($query->getModel()->qualifyColumn('organization_id'), $id);
    }
}
