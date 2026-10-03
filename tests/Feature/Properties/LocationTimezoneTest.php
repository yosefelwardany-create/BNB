<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Domain\Channels\Services\HostexPropertyFields;
use App\Domain\Properties\Services\LocationTimezone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocationTimezoneTest extends TestCase
{
    public function test_an_explicit_canadian_postal_address_fills_its_component_fields(): void
    {
        $fields = app(HostexPropertyFields::class);
        $this->assertSame([
            'address_line_1' => '1 Example Road', 'city' => 'Etobicoke', 'state' => 'ON', 'postal_code' => 'M9B 0A1', 'country_code' => 'CA',
        ], $fields->addressValues('1 Example Road, Etobicoke, ON M9B0A1, Canada'));
        $this->assertSame([], $fields->addressValues('An ambiguous address, Canada'));
    }

    public static function locations(): array
    {
        return [
            'Toronto' => [43.65, -79.38, 'America/Toronto'],
            'Vancouver' => [49.28, -123.12, 'America/Vancouver'],
            'Regina' => [50.45, -104.61, 'America/Regina'],
            'Newfoundland' => [47.56, -52.71, 'America/St_Johns'],
            'Cairo' => [30.04, 31.24, 'Africa/Cairo'],
            'Tokyo' => [35.68, 139.69, 'Asia/Tokyo'],
            'ocean' => [0, 0, null],
            'missing' => [null, null, null],
            'invalid' => [91, 0, null],
        ];
    }

    #[DataProvider('locations')]
    public function test_location_is_resolved_without_country_wide_assumptions(mixed $lat, mixed $lon, ?string $expected): void
    {
        $this->assertSame($expected, app(LocationTimezone::class)->resolve($lat, $lon));
    }
}
