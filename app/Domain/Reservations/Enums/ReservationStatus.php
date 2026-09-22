<?php

declare(strict_types=1);

namespace App\Domain\Reservations\Enums;

/**
 * The reservation lifecycle.
 *
 * The transition table is the single place that decides what may follow what.
 * Keeping it here rather than scattered through services means an illegal
 * transition is impossible regardless of whether the change arrives from the
 * admin interface, a channel webhook, an automation or the public API.
 *
 *   inquiry ──> quote ──> tentative ──> confirmed ──> checked_in ──> checked_out
 *      │          │            │             │              │
 *      └──────────┴────────────┴─────────────┴──> cancelled │
 *                                              └──> no_show ┘
 */
enum ReservationStatus: string
{
    /** A guest has asked about dates; nothing is held. */
    case Inquiry = 'inquiry';

    /** A price has been quoted; still nothing is held. */
    case Quote = 'quote';

    /** Inventory is held temporarily while payment or approval completes. */
    case Tentative = 'tentative';

    /** The booking is firm. Inventory is committed. */
    case Confirmed = 'confirmed';

    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
    case Cancelled = 'cancelled';

    /** The guest never arrived. Distinct from a cancellation for reporting. */
    case NoShow = 'no_show';

    /**
     * Whether a reservation in this state occupies inventory.
     *
     * This is the definition the availability engine works from: everything
     * that blocks a night, and nothing that does not.
     *
     * @return list<string>
     */
    public static function blockingValues(): array
    {
        return [
            self::Tentative->value,
            self::Confirmed->value,
            self::CheckedIn->value,
            self::CheckedOut->value,
        ];
    }

    public function blocksInventory(): bool
    {
        return in_array($this->value, self::blockingValues(), true);
    }

    /**
     * Whether the reservation counts as revenue-bearing business.
     *
     * @return list<string>
     */
    public static function revenueValues(): array
    {
        return [
            self::Confirmed->value,
            self::CheckedIn->value,
            self::CheckedOut->value,
        ];
    }

    public function isRevenueBearing(): bool
    {
        return in_array($this->value, self::revenueValues(), true);
    }

    /** A state from which nothing further happens. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::CheckedOut, self::Cancelled, self::NoShow], true);
    }

    public function isCancelled(): bool
    {
        return in_array($this, [self::Cancelled, self::NoShow], true);
    }

    /**
     * Whether a booking in this state is firm enough to charge for, schedule
     * cleaning around and publish to channels.
     */
    public function isCommitted(): bool
    {
        return in_array($this, [self::Confirmed, self::CheckedIn, self::CheckedOut], true);
    }

    /**
     * The states this one may move to.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Inquiry => [self::Quote, self::Tentative, self::Confirmed, self::Cancelled],
            self::Quote => [self::Tentative, self::Confirmed, self::Cancelled],
            self::Tentative => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::CheckedIn, self::Cancelled, self::NoShow],
            self::CheckedIn => [self::CheckedOut, self::Cancelled],

            // Terminal states. Reinstating a cancelled booking is handled as
            // an explicit, separately authorised operation rather than as an
            // ordinary transition, because it has to re-acquire inventory that
            // may since have been sold.
            self::CheckedOut, self::Cancelled, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::NoShow => 'No show',
            self::CheckedIn => 'Checked in',
            self::CheckedOut => 'Checked out',
            default => ucfirst($this->value),
        };
    }

    /**
     * A colour hint for calendars and status chips. Kept with the enum so
     * every surface labels a state the same way.
     */
    public function colour(): string
    {
        return match ($this) {
            self::Inquiry, self::Quote => 'slate',
            self::Tentative => 'amber',
            self::Confirmed => 'emerald',
            self::CheckedIn => 'sky',
            self::CheckedOut => 'zinc',
            self::Cancelled => 'rose',
            self::NoShow => 'orange',
        };
    }
}
