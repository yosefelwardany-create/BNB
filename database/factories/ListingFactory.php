<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Listings\Models\Listing;
use App\Domain\Properties\Enums\ListingStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    protected $model = Listing::class;

    public function definition(): array
    {
        return [
            'name' => 'Primary listing',
            'slug' => 'listing-'.Str::lower(Str::random(8)),
            'status' => ListingStatus::Draft,
            'currency' => 'EUR',
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => ListingStatus::Published,
            'published_at' => now(),
        ]);
    }
}
