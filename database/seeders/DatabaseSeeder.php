<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * What a fresh database needs.
 *
 * Two different things live behind `db:seed`, and conflating them is how a
 * production deployment ends up with a demo portfolio in it:
 *
 *  - **Reference data** — the permission registry and the amenity catalogue.
 *    These are part of the application, not sample content, and every
 *    environment needs them. They are idempotent, so re-running is safe.
 *  - **Demo data** — a worked example of a property business. Useful on a
 *    laptop or a review environment, never on a customer's database.
 *
 * Only the first runs by default. The demo is opt-in:
 *
 *     php artisan db:seed --class=DemoSeeder
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        Artisan::call('permissions:sync');
        Artisan::call('amenities:sync');

        $this->command?->info('Reference data is in place.');
        $this->command?->line(
            '  Run `php artisan db:seed --class=DemoSeeder` for a worked example portfolio.',
        );
    }
}
