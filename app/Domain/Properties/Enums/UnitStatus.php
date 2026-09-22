<?php

declare(strict_types=1);

namespace App\Domain\Properties\Enums;

/**
 * Whether a unit can currently receive guests.
 *
 * A unit that is out of service still holds its history and its future
 * reservations; the availability engine simply refuses to sell new nights in
 * it, which is what an operator means by "take 3B off the market".
 */
enum UnitStatus: string
{
    case Available = 'available';
    case OutOfService = 'out_of_service';
    case Maintenance = 'maintenance';
    case Renovation = 'renovation';

    public function isSellable(): bool
    {
        return $this === self::Available;
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }
}
