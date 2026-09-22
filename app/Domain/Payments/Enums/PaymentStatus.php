<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

/**
 * Where a payment stands.
 *
 *   pending ─> authorized ─> captured ─> partially_refunded ─> refunded
 *      │            │
 *      │            └─> voided
 *      └─> requires_action ─> authorized
 *      └─> failed
 *
 * `authorized` and `captured` are separate because a card held at booking and
 * taken on arrival is the normal shape of this business: the money is
 * committed but not ours, and a report that conflated the two would overstate
 * cash by every unarrived booking.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case Failed = 'failed';
    case Voided = 'voided';

    /** Whether the money has actually been taken. */
    public function isCaptured(): bool
    {
        return in_array($this, [self::Captured, self::PartiallyRefunded, self::Refunded], true);
    }

    /** Whether the payment still has a claim on the guest's card. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::RequiresAction, self::Authorized], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Refunded, self::Failed, self::Voided], true);
    }

    /** Whether this state counts towards what a reservation has been paid. */
    public function countsAsPaid(): bool
    {
        return $this->isCaptured();
    }

    public function label(): string
    {
        return match ($this) {
            self::RequiresAction => 'Requires action',
            self::PartiallyRefunded => 'Partially refunded',
            default => ucfirst($this->value),
        };
    }

    public function colour(): string
    {
        return match ($this) {
            self::Captured => 'emerald',
            self::Authorized => 'sky',
            self::Pending, self::RequiresAction => 'amber',
            self::PartiallyRefunded => 'indigo',
            self::Refunded => 'zinc',
            self::Failed => 'rose',
            self::Voided => 'slate',
        };
    }
}
