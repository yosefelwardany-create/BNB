<?php

declare(strict_types=1);

namespace Tests\Unit\Channels;

use App\Domain\Channels\Support\ImportCadence;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * How often each stage of the automatic Hostex import runs.
 *
 * Bookings and messages within minutes; availability, transactions and listing
 * discovery hourly; property details, photographs and prices every six hours.
 */
class ImportCadenceTest extends TestCase
{
    public function test_a_first_run_does_every_stage(): void
    {
        $this->assertSame(
            ['listings', 'properties', 'availability', 'reservations', 'transactions', 'messages'],
            ImportCadence::dueStages([], CarbonImmutable::parse('2026-10-07 12:00:00')),
        );
    }

    public function test_five_minutes_later_only_bookings_and_messages_are_due(): void
    {
        $ran = CarbonImmutable::parse('2026-10-07 12:00:00');

        $this->assertSame(
            ['reservations', 'messages'],
            ImportCadence::dueStages($this->allRanAt($ran), $ran->addMinutes(5)),
        );
    }

    public function test_an_hour_later_the_hourly_stages_join_them(): void
    {
        $ran = CarbonImmutable::parse('2026-10-07 12:00:00');

        $this->assertSame(
            ['listings', 'availability', 'reservations', 'transactions', 'messages'],
            ImportCadence::dueStages($this->allRanAt($ran), $ran->addHour()),
        );
    }

    public function test_property_details_wait_six_hours(): void
    {
        $ran = CarbonImmutable::parse('2026-10-07 12:00:00');

        $this->assertNotContains('properties', ImportCadence::dueStages($this->allRanAt($ran), $ran->addHours(5)));
        $this->assertContains('properties', ImportCadence::dueStages($this->allRanAt($ran), $ran->addHours(6)));
    }

    public function test_a_check_a_few_seconds_early_still_counts(): void
    {
        // The background check runs once a minute; a stage last run 4m59s ago
        // must not wait a whole extra cycle.
        $ran = CarbonImmutable::parse('2026-10-07 12:00:00');

        $this->assertContains('reservations', ImportCadence::dueStages($this->allRanAt($ran), $ran->addSeconds(299)));
    }

    public function test_an_unreadable_stamp_makes_the_stage_due_rather_than_never(): void
    {
        $ran = CarbonImmutable::parse('2026-10-07 12:00:00');
        $stamps = $this->allRanAt($ran);
        $stamps['properties'] = 'not a date';

        $this->assertContains('properties', ImportCadence::dueStages($stamps, $ran->addMinute()));
    }

    /**
     * @return array<string, string>
     */
    private function allRanAt(CarbonImmutable $at): array
    {
        return array_fill_keys(array_keys(ImportCadence::INTERVALS), $at->toIso8601String());
    }
}
