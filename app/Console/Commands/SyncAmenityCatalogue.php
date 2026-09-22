<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Properties\Models\Amenity;
use App\Domain\Properties\Support\AmenityCatalogue;
use Illuminate\Console\Command;

/**
 * Reconciles the platform amenity catalogue with the code definition.
 *
 * Run on deploy. Amenities are never deleted here: a property referencing one
 * would lose data, and channel mappings would break.
 */
class SyncAmenityCatalogue extends Command
{
    protected $signature = 'amenities:sync';

    protected $description = 'Synchronise the shared amenity catalogue';

    public function handle(): int
    {
        $created = 0;
        $updated = 0;
        $position = 0;

        foreach (AmenityCatalogue::all() as $key => [$name, $category, $highlight]) {
            $amenity = Amenity::query()
                ->whereNull('organization_id')
                ->where('key', $key)
                ->first();

            if ($amenity === null) {
                Amenity::query()->create([
                    'organization_id' => null,
                    'key' => $key,
                    'name' => $name,
                    'category' => $category,
                    'is_highlight' => $highlight,
                    'position' => $position++,
                ]);

                $created++;

                continue;
            }

            $amenity->fill([
                'name' => $name,
                'category' => $category,
                'is_highlight' => $highlight,
                'position' => $position++,
            ]);

            if ($amenity->isDirty()) {
                $amenity->save();
                $updated++;
            }
        }

        $this->components->info(sprintf(
            'Amenity catalogue: %d created, %d updated, %d total.',
            $created,
            $updated,
            Amenity::query()->whereNull('organization_id')->count(),
        ));

        return self::SUCCESS;
    }
}
