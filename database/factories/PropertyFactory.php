<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Properties\Enums\PropertyStatus;
use App\Domain\Properties\Enums\PropertyType;
use App\Domain\Properties\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    protected $model = Property::class;

    public function definition(): array
    {
        $name = $this->faker->streetName().' '.$this->faker->randomElement([
            'Apartment', 'Residence', 'Loft', 'House', 'Retreat', 'Suite',
        ]);

        return [
            'name' => $name,
            'internal_name' => Str::upper(Str::random(3)).' — '.$name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'property_type' => PropertyType::Apartment,
            'rental_kind' => 'entire_place',
            'status' => PropertyStatus::Draft,

            'address_line_1' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'postal_code' => $this->faker->postcode(),
            'country_code' => 'PT',
            'latitude' => $this->faker->latitude(36, 42),
            'longitude' => $this->faker->longitude(-9, -6),

            'timezone' => 'Europe/Lisbon',
            'currency' => 'EUR',

            'bedrooms' => $bedrooms = $this->faker->numberBetween(1, 4),
            'bathrooms' => $this->faker->randomElement([1, 1.5, 2, 2.5]),
            'beds' => $bedrooms + $this->faker->numberBetween(0, 2),
            'max_occupancy' => $bedrooms * 2,
            'max_pets' => 0,

            'summary' => $this->faker->sentence(12),
            'description' => $this->faker->paragraphs(3, true),
            'house_rules' => 'No smoking. No parties. Quiet hours after 10pm.',

            'check_in_time' => '15:00',
            'check_out_time' => '11:00',

            // Amounts are minor units: 12000 = 120.00 EUR.
            'base_rate' => $this->faker->numberBetween(8000, 25000),
            'cleaning_fee' => $this->faker->numberBetween(3000, 8000),
            'security_deposit' => 0,
            'minimum_nights' => 2,

            'cleaning_duration_minutes' => 120,
            'preparation_hours' => 0,
        ];
    }

    /**
     * A property that satisfies every activation requirement.
     */
    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => PropertyStatus::Active,
            'activated_at' => now(),
        ]);
    }

    public function multiUnit(int $bedrooms = 1): static
    {
        return $this->state(fn (): array => [
            'is_multi_unit' => true,
            'property_type' => PropertyType::Aparthotel,
            'bedrooms' => $bedrooms,
            'max_occupancy' => $bedrooms * 2,
        ]);
    }

    public function inTimezone(string $timezone, string $currency = 'EUR'): static
    {
        return $this->state(fn (): array => [
            'timezone' => $timezone,
            'currency' => $currency,
        ]);
    }
}
