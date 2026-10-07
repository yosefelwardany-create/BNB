<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * How often each stage of the automatic import runs.
 *
 * The background import used to repeat every stage every five minutes: the
 * property's details, every photograph and a year of prices included, almost
 * all of it unchanged since five minutes before. Reading it all back out of
 * the database 288 times a day used up the database's monthly network
 * allowance in under a week.
 *
 * So each stage runs as often as what it brings in actually changes:
 *
 * - reservations and messages every 5 minutes, because a new booking or a
 *   guest's question should appear within minutes;
 * - availability, transactions and listing discovery every hour;
 * - property details, photographs and prices every 6 hours.
 *
 * A pull somebody asks for (the Pull button, or a queued request) still runs
 * every stage. A stage that fails is not stamped, so it is retried on the
 * next check rather than waiting out its interval.
 */
final class ImportCadence
{
    /** @var array<string, int> minutes between automatic runs, per stage */
    public const INTERVALS = [
        'listings' => 60,
        'properties' => 360,
        'availability' => 60,
        'reservations' => 5,
        'transactions' => 60,
        'messages' => 5,
    ];

    /**
     * The stages due now, given when each last completed.
     *
     * One minute of tolerance: the background check runs once a minute, so a
     * stage last run 4 minutes 59 seconds ago would otherwise wait a whole
     * extra cycle.
     *
     * @param  array<string, mixed>  $lastRun  stage => ISO 8601 time it last completed
     * @return list<string>
     */
    public static function dueStages(array $lastRun, CarbonImmutable $now): array
    {
        $due = [];

        foreach (self::INTERVALS as $stage => $minutes) {
            $at = $lastRun[$stage] ?? null;

            if (! is_string($at)) {
                $due[] = $stage;

                continue;
            }

            try {
                $next = CarbonImmutable::parse($at)->addMinutes($minutes)->subMinute();
            } catch (Throwable) {
                $due[] = $stage;

                continue;
            }

            if ($next->lte($now)) {
                $due[] = $stage;
            }
        }

        return $due;
    }

    /**
     * How a skipped stage is described on the Channels screen.
     */
    public static function skippedNote(string $stage): string
    {
        $minutes = self::INTERVALS[$stage] ?? null;

        if ($minutes === null) {
            return 'Not due yet.';
        }

        $every = $minutes >= 60
            ? sprintf('%d hour%s', intdiv($minutes, 60), $minutes >= 120 ? 's' : '')
            : sprintf('%d minutes', $minutes);

        return sprintf('not due yet; refreshed automatically every %s, or now with Pull.', $every);
    }
}
