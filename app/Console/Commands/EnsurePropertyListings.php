<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Listings\Services\ListingService;
use App\Domain\Properties\Models\Property;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Give a listing to every property that has none.
 *
 * A property without a listing cannot be booked, put on a calendar, priced or
 * connected to a channel, because all four take a listing rather than a
 * property. Properties created before that became automatic are in exactly that
 * state, and the symptom is a reservation form whose listing picker is empty.
 *
 * This is additive and idempotent: it creates what is missing and leaves
 * everything else alone. It never edits an existing listing, never changes a
 * property, and removes nothing. Run it as often as you like.
 */
class EnsurePropertyListings extends Command
{
    protected $signature = 'properties:ensure-listings
        {--dry-run : List what would be created and create nothing}';

    protected $description = 'Create the missing primary listing for properties that have none';

    public function handle(TenantContext $tenancy, ListingService $listings): int
    {
        return $tenancy->withoutScope(function () use ($listings): int {
            $properties = Property::query()
                ->whereDoesntHave('listings')
                ->orderBy('organization_id')
                ->orderBy('created_at')
                ->get();

            if ($properties->isEmpty()) {
                $this->components->info('Every property already has a listing.');

                return self::SUCCESS;
            }

            $dryRun = (bool) $this->option('dry-run');

            foreach ($properties as $property) {
                $this->line(sprintf(
                    ' %s <fg=gray>%s</> <fg=gray>(%s)</>',
                    $dryRun ? '<fg=yellow>would create</>' : '<fg=green>created</>',
                    $property->name,
                    $property->status->value,
                ));

                if ($dryRun) {
                    continue;
                }

                // Bound to the property's own tenant, because a listing carries
                // the organization id and the global scope is off in here.
                $listings->primaryFor($property);
            }

            $this->newLine();
            $this->components->info(sprintf(
                '%d listing(s) %s.',
                $properties->count(),
                $dryRun ? 'would be created' : 'created',
            ));

            return self::SUCCESS;
        });
    }
}
