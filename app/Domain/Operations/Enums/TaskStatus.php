<?php

declare(strict_types=1);

namespace App\Domain\Operations\Enums;

/**
 * The life of a piece of work.
 *
 *   pending ─> assigned ─> accepted ─> in_progress ─> completed
 *                  │           │            │
 *                  └───────────┴─> blocked ─┘
 *   any ─> cancelled
 *
 * `accepted` is separate from `assigned` deliberately: a cleaner being given a
 * job and a cleaner agreeing to it are different facts, and the gap between
 * them is what a coordinator watches on the morning of a turnover.
 */
enum TaskStatus: string
{
    case Pending = 'pending';
    case Assigned = 'assigned';
    case Accepted = 'accepted';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Assigned, self::InProgress, self::Cancelled],
            self::Assigned => [self::Accepted, self::InProgress, self::Blocked, self::Pending, self::Cancelled],
            self::Accepted => [self::InProgress, self::Blocked, self::Assigned, self::Cancelled],
            self::InProgress => [self::Completed, self::Blocked, self::Cancelled],
            self::Blocked => [self::InProgress, self::Assigned, self::Cancelled],

            // A completed task keeps its completion history; correcting one
            // means recording a new task, not reopening this one.
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Completed, self::Cancelled], true);
    }

    public function isTerminal(): bool
    {
        return ! $this->isOpen();
    }

    /** Work that still needs somebody put on it. */
    public function needsAssignment(): bool
    {
        return $this === self::Pending;
    }

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'In progress',
            default => ucfirst($this->value),
        };
    }

    public function colour(): string
    {
        return match ($this) {
            self::Pending => 'slate',
            self::Assigned => 'sky',
            self::Accepted => 'indigo',
            self::InProgress => 'amber',
            self::Blocked => 'rose',
            self::Completed => 'emerald',
            self::Cancelled => 'zinc',
        };
    }
}
