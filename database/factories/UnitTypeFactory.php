<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Properties\Models\UnitType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UnitType>
 */
class UnitTypeFactory extends Factory
{
    protected $model = UnitType::class;

    public function definition(): array
    {
        $name = $this->faker->randomElement([
            'Studio', 'One-bedroom', 'One-bedroom sea view', 'Two-bedroom', 'Penthouse',
        ]);

        return [
            'name' => $name,
            'code' => Str::upper(Str::random(4)),
            'bedrooms' => 1,
            'bathrooms' => 1,
            'beds' => 1,
            'max_occupancy' => 2,
            'is_active' => true,
            'position' => 0,
        ];
    }
}
