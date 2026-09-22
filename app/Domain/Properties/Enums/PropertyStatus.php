<?php

declare(strict_types=1);

namespace App\Domain\Properties\Enums;

/**
 * Where a property sits in its lifecycle.
 *
 * Archiving never deletes: a property with historical reservations, ledger
 * entries and owner statements must remain resolvable forever, so it is taken
 * out of circulation rather than removed.
 */
enum PropertyStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    /** Whether new reservations may be taken. */
    public function isBookable(): bool
    {
        return $this === self::Active;
    }

    /** Whether the property appears in operational screens by default. */
    public function isOperational(): bool
    {
        return in_array($this, [self::Active, self::Inactive], true);
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
