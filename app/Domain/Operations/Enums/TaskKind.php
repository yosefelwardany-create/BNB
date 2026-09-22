<?php

declare(strict_types=1);

namespace App\Domain\Operations\Enums;

enum TaskKind: string
{
    case Cleaning = 'cleaning';
    case Maintenance = 'maintenance';
    case Inspection = 'inspection';
    case Restocking = 'restocking';
    case Preparation = 'preparation';
    case GuestRequest = 'guest_request';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::GuestRequest => 'Guest request',
            default => ucfirst($this->value),
        };
    }

    /**
     * Whether work of this kind must finish before the next guest arrives.
     * Used to sort a day's board and to escalate what is running late.
     */
    public function blocksArrival(): bool
    {
        return in_array($this, [self::Cleaning, self::Preparation, self::Inspection], true);
    }

    /**
     * Whether the cost is normally the owner's rather than the manager's.
     * The management agreement has the final say; this is only the default.
     */
    public function defaultBillableTo(): string
    {
        return match ($this) {
            self::Maintenance, self::Restocking => 'owner',
            self::Cleaning => 'guest',
            default => 'manager',
        };
    }

    public function defaultPriority(): TaskPriority
    {
        return match ($this) {
            self::Maintenance => TaskPriority::High,
            self::GuestRequest => TaskPriority::High,
            default => TaskPriority::Normal,
        };
    }
}
