<?php

declare(strict_types=1);

namespace App\Domain\Properties\Enums;

/**
 * What the guest actually gets: the whole place, a private room within it, or
 * a bed in a shared room. Channels treat these very differently, and it
 * changes how occupancy and availability are counted.
 */
enum RentalKind: string
{
    case EntirePlace = 'entire_place';
    case PrivateRoom = 'private_room';
    case SharedRoom = 'shared_room';

    public function label(): string
    {
        return match ($this) {
            self::EntirePlace => 'Entire place',
            self::PrivateRoom => 'Private room',
            self::SharedRoom => 'Shared room',
        };
    }

    /**
     * When only part of a property is let, other parts remain sellable, so
     * availability is tracked per unit rather than per property.
     */
    public function isPartial(): bool
    {
        return $this !== self::EntirePlace;
    }
}
