<?php

declare(strict_types=1);

namespace App\Domain\Agents\Enums;

/**
 * The things an agent may be asked to do, and nothing else.
 *
 * An enumerated list rather than access to an API, and that is the whole point.
 * "Let the agent do anything" sounds like capability and is actually the absence
 * of a boundary: a model with open write access to a channel will, eventually
 * and confidently, cancel a booking it misread. Naming the actions means a
 * mistake can only be one of these, each of which has a known blast radius and a
 * known way back.
 *
 * Every capability carries its own default about whether a person sees it first.
 * The defaults are not a judgement about the model — they are about what cannot
 * be undone:
 *
 *  - **A note** is internal and editable. Nobody is harmed by a wrong one.
 *  - **A message** reaches a guest and cannot be recalled. A wrong one is a
 *    conversation somebody has to have.
 *  - **A blocked night** costs a booking that never happens, invisibly. Nobody
 *    notices an empty calendar the way they notice a double booking.
 *  - **A rate** is money, applied to every booking taken before anybody looks.
 *  - **A booking** belongs to a guest who arranged their travel around it.
 *
 * An operator can loosen any of these per property. They cannot loosen them by
 * accident, and the screen says what each one means before they do.
 */
enum AgentCapability: string
{
    /** Write an internal note on a conversation or a booking. */
    case AddNote = 'add_note';

    /** Reply to a guest in their own thread on the channel. */
    case SendMessage = 'send_message';

    /** Close nights on the calendar — maintenance, an owner stay. */
    case BlockDates = 'block_dates';

    /** Re-open nights that were closed. */
    case UnblockDates = 'unblock_dates';

    /** Change what a night costs. */
    case SetRate = 'set_rate';

    /** Cancel a booking that exists. */
    case CancelReservation = 'cancel_reservation';

    case UpdateProperty = 'update_property';

    case CreateTask = 'create_task';
    case LiveBlock = 'live_block_dates';
    case LiveUnblock = 'live_unblock_dates';
    case LiveRate = 'live_set_rate';
    case LiveSettings = 'live_listing_settings';

    public function isLivePropertyWrite(): bool
    {
        return in_array($this, [self::LiveBlock, self::LiveUnblock, self::LiveRate, self::LiveSettings], true);
    }

    /**
     * Whether this may run on the agent's own judgement, before anybody looks.
     *
     * The default, which an operator may change per property. Only the one that
     * cannot hurt anybody is true.
     */
    public function defaultsToAutonomous(): bool
    {
        return $this === self::AddNote;
    }

    /**
     * Whether an operator may ever let this run unattended.
     *
     * Cancelling somebody's booking is never that. It is not a risk to be
     * traded off: the guest arranged their travel around it, the money has
     * moved, and there is no version of "the agent misunderstood" that makes
     * that acceptable. A constant rather than a setting, so nothing a customer
     * types into their own configuration can turn it on.
     */
    public function mayEverBeAutonomous(): bool
    {
        return ! in_array($this, [self::CancelReservation, self::SendMessage], true);
    }

    /**
     * The permission somebody needs to approve this.
     *
     * The same one they would need to do it by hand, and that equivalence is the
     * point. Without it the agent is a way around the permission system: a
     * cleaner with `properties.update` could ask the bot to cancel a booking and
     * then approve its own proposal, having been given no authority to cancel
     * anything. Asking is cheap and approving is the act, so this is checked at
     * approval rather than at proposal.
     */
    public function permission(): string
    {
        return match ($this) {
            self::AddNote => 'messages.view',
            self::SendMessage => 'messages.send',
            self::BlockDates, self::UnblockDates => 'calendar.update',
            self::SetRate => 'pricing.update',
            self::CancelReservation => 'reservations.cancel',
            self::UpdateProperty => 'properties.update',
            self::CreateTask => 'tasks.create',
            self::LiveBlock, self::LiveUnblock => 'calendar.update',
            self::LiveRate, self::LiveSettings => 'pricing.update',
        };
    }

    /**
     * What this does, in the words the screen uses.
     */
    public function label(): string
    {
        return match ($this) {
            self::AddNote => 'Leave a note',
            self::SendMessage => 'Reply to a guest',
            self::BlockDates => 'Block nights',
            self::UnblockDates => 'Re-open nights',
            self::SetRate => 'Change a rate',
            self::CancelReservation => 'Cancel a booking',
            self::UpdateProperty => 'Update property information',
            self::CreateTask => 'Create an operational task',
            self::LiveBlock => 'Block dates on Hostex',
            self::LiveUnblock => 'Reopen dates on Hostex',
            self::LiveRate => 'Change Airbnb nightly prices',
            self::LiveSettings => 'Change Airbnb fees and booking settings',
        };
    }

    /**
     * What is at stake, so a person approving one knows what they are approving.
     */
    public function consequence(): string
    {
        return match ($this) {
            self::AddNote => 'Internal only. Nobody outside the company sees it.',
            self::SendMessage => 'Goes to the guest on the channel and cannot be recalled.',
            self::BlockDates => 'Nights stop being bookable. An empty calendar is not noticed the '
                .'way a double booking is.',
            self::UnblockDates => 'Nights become bookable again, including any somebody closed on purpose.',
            self::SetRate => 'Money. It applies to every booking taken before anybody looks again.',
            self::CancelReservation => 'A guest loses a booking they arranged their travel around. '
                .'Always confirmed by a person.',
            self::UpdateProperty => 'Updates local property fields. Does not publish changes to Airbnb.',
            self::CreateTask => 'Creates internal work for this property. Does not contact vendors or guests.',
            self::LiveBlock, self::LiveUnblock => 'Changes only the requested nights on this property in Hostex and its connected channels.',
            self::LiveRate => 'Pushes only the requested nightly prices to this property’s Airbnb listing.',
            self::LiveSettings => 'Pushes only the explicitly requested fees or booking settings to this property’s Airbnb listing.',
        };
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
