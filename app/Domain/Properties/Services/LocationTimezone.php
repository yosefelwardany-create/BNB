<?php

declare(strict_types=1);

namespace App\Domain\Properties\Services;

use DateTimeZone;
use Symfony\Component\Process\Process;
use Throwable;

/** Resolve coordinates locally against timezone boundaries, never a country-wide guess. */
class LocationTimezone
{
    private array $resolved = [];

    public function resolve(mixed $latitude, mixed $longitude): ?string
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)
            || ! is_finite((float) $latitude) || ! is_finite((float) $longitude)
            || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            return null;
        }

        $key = $latitude.','.$longitude;
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        try {
            $process = new Process([
                'node', base_path('tools/timezone/lookup.cjs'), (string) $latitude, (string) $longitude,
            ], timeout: 10);
            $process->run();
            $zones = $process->isSuccessful() ? json_decode($process->getOutput(), true) : null;
            // Borders and disputed zones can have multiple answers. Require a
            // unique land timezone rather than choosing an arbitrary answer.
            $zone = is_array($zones) && count($zones) === 1 ? $zones[0] : null;

            return $this->resolved[$key] = is_string($zone) && ! str_starts_with($zone, 'Etc/')
                && in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
                    ? $zone : null;
        } catch (Throwable) {
            return $this->resolved[$key] = null;
        }
    }
}
