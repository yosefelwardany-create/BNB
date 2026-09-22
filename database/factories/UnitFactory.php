<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Properties\Enums\UnitStatus;
use App\Domain\Properties\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        $number = $this->faker->unique()->numberBetween(101, 999);

        return [
            'name' => 'Unit '.$number,
            'code' => (string) $number,
            'floor' => (string) intdiv($number, 100),
            'status' => UnitStatus::Available,
            'is_bookable' => true,
            'position' => 0,
        ];
    }

    public function outOfService(): static
    {
        return $this->state(fn (): array => [
            'status' => UnitStatus::OutOfService,
            'is_bookable' => false,
        ]);
    }
}
