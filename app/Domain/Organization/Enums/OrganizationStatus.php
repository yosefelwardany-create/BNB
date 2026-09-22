<?php

declare(strict_types=1);

namespace App\Domain\Organization\Enums;

enum OrganizationStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    /**
     * Whether members of the organization may sign in and operate it.
     */
    public function allowsAccess(): bool
    {
        return match ($this) {
            self::Trial, self::Active, self::PastDue => true,
            self::Suspended, self::Cancelled => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Trial',
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Suspended => 'Suspended',
            self::Cancelled => 'Cancelled',
        };
    }
}
