<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Domain\Listings\Models\Listing;
use App\Domain\Pricing\Services\PricingEngine;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every night on the calendar carries its price: the one a guest pays and a
 * channel such as Channex is sent, worked out the same way, so what the grid
 * shows is what went out. A night priced at nothing shows as nothing rather
 * than as an empty cell somebody reads as "the usual price".
 */
class CalendarPricesTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_night_carries_the_price_a_channel_is_sent(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $this->postJson('/api/v1/properties', [
            'name' => 'Twin Room',
            'property_type' => 'apartment',
            'address_line_1' => '12 Test Street',
            'city' => 'London',
            'country_code' => 'GB',
            'max_occupancy' => 2,
            'base_rate' => 9500,
        ])->assertCreated();

        $listing = $this->getJson('/api/v1/listings')->assertOk()->json('data.0.id');
        $from = CarbonImmutable::today()->addDays(10);
        $to = $from->addDays(6);

        // Christmas pricing on two nights, set by hand.
        $this->patchJson("/api/v1/calendar/listings/{$listing}/days", [
            'from' => $from->addDays(2)->toDateString(),
            'to' => $from->addDays(3)->toDateString(),
            'rate_override' => 17500,
        ])->assertOk();

        $days = collect($this->getJson(sprintf('/api/v1/calendar?from=%s&to=%s', $from->toDateString(), $to->toDateString()))
            ->assertOk()
            ->json('listings.0.days'))
            ->keyBy('date');

        $this->assertSame(9500, $days[$from->toDateString()]['rate']);
        $this->assertSame(17500, $days[$from->addDays(2)->toDateString()]['rate']);
        $this->assertSame(17500, $days[$from->addDays(2)->toDateString()]['rate_override']);
        // The last night shown is priced too.
        $this->assertSame(9500, $days[$days->keys()->last()]['rate']);

        // Exactly what Channex would be sent for those nights.
        $sent = $this->app->make(PricingEngine::class)->rateCalendar(
            $this->app->make(TenantContext::class)->withoutScope(fn () => Listing::query()->findOrFail($listing)),
            $from,
            $to->addDay(),
        );

        foreach ($days as $date => $day) {
            $this->assertSame($sent[$date]->minorUnits, $day['rate'], $date);
        }
    }

    public function test_a_listing_left_at_a_price_of_nothing_shows_it(): void
    {
        ['organization' => $organization, 'user' => $user] = $this->createTenantWithAdmin();
        $this->actingAsUser($user, $organization);

        $this->postJson('/api/v1/properties', [
            'name' => 'Double Room',
            'property_type' => 'apartment',
            'address_line_1' => '10 Test Street',
            'city' => 'London',
            'country_code' => 'GB',
            'max_occupancy' => 2,
            'base_rate' => 11500,
        ])->assertCreated();

        $listing = $this->getJson('/api/v1/listings')->assertOk()->json('data.0.id');
        // The listing's own price, set to nothing: it no longer takes the
        // home's 115.00.
        $this->patchJson("/api/v1/listings/{$listing}", ['base_rate' => 0])->assertOk();

        $from = CarbonImmutable::today()->addDays(10);

        $this->getJson(sprintf('/api/v1/calendar?from=%s&to=%s', $from->toDateString(), $from->addDays(3)->toDateString()))
            ->assertOk()
            ->assertJsonPath('listings.0.days.0.rate', 0);
    }
}
