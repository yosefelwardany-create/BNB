<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyPhoto;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sample photos for the Juan Lopez account.
 *
 * Free stock photos from Pexels, stored as links the way photos imported from
 * Airbnb are, so they survive a redeploy (uploaded files on the local disk do
 * not). Each is captioned as a sample, not a photograph of the property.
 *
 * Only a property with no photos at all receives them: once real photos exist,
 * or after a first run, this changes nothing.
 */
class JuanLopezSamplePhotosSeeder extends Seeder
{
    /** Pexels photo ids per property reference, cover first. */
    private const PHOTOS = [
        'JL-001' => [7060814, 4440214, 6032225],
        'JL-002' => [7587880, 6588599, 6207825, 6207945],
        'JL-003' => [6492403, 6492390, 6492402],
        'JL-004' => [7174109, 6585628, 6934189, 3933240, 7061339],
        'JL-005' => [4946986, 3075974, 6312353, 3144580],
    ];

    public function run(): void
    {
        $tenancy = app(TenantContext::class);

        $organization = $tenancy->withoutScope(
            fn () => Organization::query()->where('slug', JuanLopezDemoSeeder::SLUG)->first(),
        );

        if ($organization === null) {
            return;
        }

        $added = $tenancy->runAs($organization, fn (): int => DB::transaction(function (): int {
            $added = 0;

            foreach (self::PHOTOS as $reference => $ids) {
                $property = Property::query()->where('reference', $reference)->first();

                if ($property === null || PropertyPhoto::query()->where('property_id', $property->getKey())->exists()) {
                    continue;
                }

                foreach ($ids as $position => $id) {
                    PropertyPhoto::query()->create([
                        'organization_id' => $property->organization_id,
                        'property_id' => $property->getKey(),
                        'disk' => 'external',
                        'path' => 'sample:pexels:'.$id,
                        'external_url' => sprintf(
                            'https://images.pexels.com/photos/%1$d/pexels-photo-%1$d.jpeg?auto=compress&cs=tinysrgb&w=1600',
                            $id,
                        ),
                        'caption' => 'Sample photo (Pexels), not a photograph of this property.',
                        'position' => $position + 1,
                        'is_cover' => $position === 0,
                    ]);

                    $added++;
                }
            }

            return $added;
        }));

        if ($added > 0) {
            $this->command?->info(sprintf('Added %d sample photos to the Juan Lopez properties.', $added));
        }
    }
}
