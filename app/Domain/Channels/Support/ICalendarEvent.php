<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use DateTimeImmutable;

/**
 * One VEVENT.
 *
 * `end` follows the iCalendar convention of being exclusive, which lines up
 * exactly with a checkout date: a stay from the 3rd to the 5th occupies the
 * nights of the 3rd and 4th.
 */
final class ICalendarEvent
{
    public function __construct(
        public readonly string $uid,
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
        public readonly string $summary,
        public readonly ?string $description = null,
        public readonly ?string $status = null,
    ) {}

    /**
     * Number of nights the event occupies.
     */
    public function nights(): int
    {
        return max(1, (int) $this->start->diff($this->end)->days);
    }

    public function isCancelled(): bool
    {
        return strtoupper((string) $this->status) === 'CANCELLED';
    }
}
