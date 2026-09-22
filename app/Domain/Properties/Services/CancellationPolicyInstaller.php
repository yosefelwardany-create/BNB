<?php

declare(strict_types=1);

namespace App\Domain\Properties\Services;

use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\CancellationPolicy;
use App\Support\Tenancy\TenantContext;

/**
 * Gives a new organization the standard cancellation policies.
 *
 * They are ordinary, fully editable records rather than hard-coded behaviour:
 * a manager can change the tiers, add their own, or stop using them entirely.
 * Re-running is safe and only adds what is missing.
 */
class CancellationPolicyInstaller
{
    public function __construct(private readonly TenantContext $tenancy) {}

    /**
     * @return int number of policies created
     */
    public function install(Organization $organization): int
    {
        return $this->tenancy->runAs($organization, function () use ($organization): int {
            $existing = CancellationPolicy::query()
                ->where('organization_id', $organization->getKey())
                ->pluck('slug')
                ->all();

            $created = 0;

            foreach (CancellationPolicy::defaults() as $definition) {
                if (in_array($definition['slug'], $existing, true)) {
                    continue;
                }

                CancellationPolicy::query()->create([
                    'organization_id' => $organization->getKey(),
                    'name' => $definition['name'],
                    'slug' => $definition['slug'],
                    'description' => $definition['description'],
                    'free_cancellation_days' => $definition['free_cancellation_days'],
                    'tiers' => $definition['tiers'],
                    // Moderate is the default because it is the shape most
                    // managers actually run: generous enough not to lose
                    // bookings, firm enough to protect a short-notice week.
                    'is_default' => $definition['slug'] === 'moderate',
                ]);

                $created++;
            }

            return $created;
        });
    }
}
