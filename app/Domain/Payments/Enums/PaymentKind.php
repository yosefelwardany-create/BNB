<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

/**
 * What a payment is for.
 *
 * The distinction that matters most is the security deposit: it is the guest's
 * money held against damage, never revenue, and it posts to a liability
 * account. Treating it as income overstates revenue and understates what is
 * owed back — an error that compounds every booking until somebody reconciles.
 */
enum PaymentKind: string
{
    case Booking = 'booking';
    case Deposit = 'deposit';
    case SecurityDeposit = 'security_deposit';
    case Upsell = 'upsell';
    case Damage = 'damage';
    case Other = 'other';

    /** Whether this money is ever recognised as revenue. */
    public function isRevenue(): bool
    {
        return $this !== self::SecurityDeposit;
    }

    /** Whether it is held on the guest's behalf and normally returned. */
    public function isRefundableHolding(): bool
    {
        return $this === self::SecurityDeposit;
    }

    public function label(): string
    {
        return match ($this) {
            self::SecurityDeposit => 'Security deposit',
            default => ucfirst($this->value),
        };
    }
}
