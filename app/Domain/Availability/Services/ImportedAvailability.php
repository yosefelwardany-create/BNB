<?php

declare(strict_types=1);

namespace App\Domain\Availability\Services;

use App\Domain\Properties\Models\Property;

class ImportedAvailability
{
    public function days(Property $property): array
    {
        $days = [];
        foreach ($property->settings['hostex']['availability'] ?? [] as $day) {
            if (is_string($day['date'] ?? null) && is_bool($day['available'] ?? null)) {
                $days[$day['date']] = $day;
            }
        }

        return $days;
    }
}
