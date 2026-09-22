<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Operations\Enums\TaskKind;
use App\Domain\Operations\Services\TaskService;
use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Instants survive the round trip through the database.
 *
 * This is load-bearing for the whole product. Every scheduled action — when a
 * clean is due, when arrival instructions go out, when a check-in happens — is
 * computed in the *property's* local clock and stored as an absolute instant.
 * If the offset is dropped on the way in, the stored moment is silently wrong
 * by that offset, and a portfolio spanning timezones is wrong by a different
 * amount in each place.
 *
 * Eloquent's default database format is `Y-m-d H:i:s`, which discards the
 * offset, so this is a behaviour the base model deliberately overrides rather
 * than one the framework gives us.
 */
class TimezonePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_utc_instant_is_stored_as_the_same_moment(): void
    {
        $organization = $this->createOrganization();

        $property = Property::factory()->active()->create([
            'organization_id' => $organization->getKey(),
            'timezone' => 'Asia/Tokyo',
        ]);

        // 10:00 in Tokyo is 01:00 UTC the same day.
        $localTenAm = CarbonImmutable::parse('2026-06-15 10:00:00', 'Asia/Tokyo');

        $task = $this->app->make(TaskService::class)->create([
            'kind' => TaskKind::Cleaning,
            'title' => 'Morning clean',
            'property_id' => $property->getKey(),
            'scheduled_start' => $localTenAm,
        ]);

        $stored = $task->fresh()->scheduled_start;

        // The same instant, however it is expressed.
        $this->assertTrue(
            $stored->equalTo($localTenAm),
            sprintf('Stored %s, expected %s.', $stored->toIso8601String(), $localTenAm->toIso8601String()),
        );

        $this->assertSame('01:00', $stored->utc()->format('H:i'));
        $this->assertSame('10:00', $stored->setTimezone('Asia/Tokyo')->format('H:i'));
    }

    public function test_the_offset_actually_reaches_the_database(): void
    {
        $organization = $this->createOrganization();

        $property = Property::factory()->active()->create([
            'organization_id' => $organization->getKey(),
            'timezone' => 'America/Mexico_City',
        ]);

        $local = CarbonImmutable::parse('2026-03-01 23:30:00', 'America/Mexico_City');

        $task = $this->app->make(TaskService::class)->create([
            'kind' => TaskKind::Maintenance,
            'title' => 'Late callout',
            'property_id' => $property->getKey(),
            'due_at' => $local,
        ]);

        // Read straight out of PostgreSQL, bypassing Eloquent's casting, so a
        // bug that cancelled itself out on the way back would still be caught.
        $raw = DB::table('tasks')
            ->where('id', $task->getKey())
            ->value('due_at');

        $this->assertTrue(
            CarbonImmutable::parse($raw)->equalTo($local),
            sprintf('The database holds %s, which is not the same moment as %s.', (string) $raw, $local->toIso8601String()),
        );
    }

    public function test_a_local_midnight_date_does_not_slip_to_the_previous_day(): void
    {
        $organization = $this->createOrganization();

        $property = Property::factory()->active()->create([
            'organization_id' => $organization->getKey(),
            'timezone' => 'Pacific/Auckland',
        ]);

        // The counterpart risk: a date column written from a timezone ahead of
        // UTC must keep its calendar day. Converting the instant to UTC would
        // turn a New Zealand check-in into the day before.
        $reservation = new Reservation;
        $reservation->forceFill([
            'organization_id' => $organization->getKey(),
            'property_id' => $property->getKey(),
            'confirmation_code' => 'TZ-TEST-1',
            'status' => 'confirmed',
            'check_in_date' => CarbonImmutable::parse('2026-07-04 00:00:00', 'Pacific/Auckland'),
            'check_out_date' => CarbonImmutable::parse('2026-07-06 00:00:00', 'Pacific/Auckland'),
            'nights' => 2,
            'currency' => 'NZD',
            'base_currency' => 'NZD',
        ])->save();

        $this->assertSame('2026-07-04', DB::table('reservations')
            ->where('id', $reservation->getKey())
            ->value('check_in_date'));
    }
}
