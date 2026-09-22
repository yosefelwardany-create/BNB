<?php

declare(strict_types=1);

namespace App\Domain\Operations\Enums;

enum TaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    /**
     * How long the work may sit before it is late, in hours. Used to set an
     * SLA when a ticket is raised.
     */
    public function slaHours(): int
    {
        return match ($this) {
            self::Urgent => 4,
            self::High => 24,
            self::Normal => 72,
            self::Low => 168,
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 4,
            self::High => 3,
            self::Normal => 2,
            self::Low => 1,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
