<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Properties\Models\Property;
use App\Domain\Reservations\Models\Reservation;
use RuntimeException;

/**
 * Loading a scenario file and finding the bookings it asks for.
 *
 * Shared by `php artisan agent:evaluate` and by the bench screen's "run the
 * suite" button, so that the number an operator sees in the browser and the
 * number CI prints come from the same code. Two implementations of "run the
 * evals" would eventually disagree, and the one somebody trusts would be
 * whichever they happened to be looking at.
 *
 * Bookings are *found*, never created. Running the suite must not write to the
 * database of an organization somebody is working in, which also means a
 * scenario can go unmatched — reported as "no booking" rather than skipped,
 * because a silently skipped security scenario is worse than a failing one.
 */
class EvalScenarioSet
{
    /**
     * Scenario sets that may be run. A caller-supplied name is checked against
     * this list rather than pasted into a path: `--set=../../.env` would
     * otherwise be a file read.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        $files = glob(base_path('database/agent-evals/*.php')) ?: [];

        return array_values(array_map(
            static fn (string $path): string => basename($path, '.php'),
            $files,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function load(string $set): array
    {
        if (! in_array($set, self::available(), true)) {
            throw new RuntimeException(sprintf('There is no scenario set called "%s".', $set));
        }

        /** @var list<array<string, mixed>> $scenarios */
        $scenarios = require base_path('database/agent-evals/'.$set.'.php');

        return $scenarios;
    }

    /**
     * One reservation per entitlement shape the scenarios name.
     *
     * The shapes are the whole reason the suite can test entitlement at all:
     * the same question asked against a paid booking and an unpaid one must
     * produce different answers, and nothing else in the scenario changes.
     *
     * @return array<string, Reservation|null>
     */
    public function bookings(Property $property): array
    {
        $base = fn () => Reservation::query()
            ->where('property_id', $property->getKey())
            ->with('property');

        return [
            'paid_arriving_tomorrow' => $base()
                ->whereIn('status', ['confirmed', 'checked_in'])
                ->where('balance_due', 0)
                ->orderBy('check_in_date')
                ->first(),

            'unpaid' => $base()
                ->whereIn('status', ['confirmed', 'checked_in'])
                ->where('balance_due', '>', 0)
                ->first(),

            'far_future' => $base()
                ->whereIn('status', ['confirmed', 'checked_in'])
                ->where('balance_due', 0)
                ->where('check_in_date', '>', now()->addWeeks(2)->toDateString())
                ->first(),

            'none' => null,
        ];
    }

    /**
     * The resolver {@see AgentEvaluator::runAll()} expects.
     *
     * @return callable(array<string, mixed>): array{0: Property, 1: ?Reservation}
     */
    public function resolver(Property $property): callable
    {
        $bookings = $this->bookings($property);

        return static fn (array $scenario): array => [
            $property,
            $bookings[$scenario['booking'] ?? 'none'] ?? null,
        ];
    }
}
