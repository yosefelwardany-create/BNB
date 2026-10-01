<?php

declare(strict_types=1);

namespace App\Domain\Agents\Enums;

/**
 * Who is asking, which decides what the agent is allowed to know.
 *
 * Not a preference and not a tone setting. The two audiences are given entirely
 * different facts, by different rules, for different reasons:
 *
 *  - **Guest** — facts a guest may be told, gated on their booking being
 *    confirmed, paid and inside its window. See {@see PropertyKnowledge}.
 *  - **Operator** — how the property is doing: occupancy, rate, revenue,
 *    bookings on the books. Gated on the *asker's own permissions*, because
 *    there is no booking in the picture and what they may know is what their
 *    role already lets them read elsewhere. See {@see OperatorKnowledge}.
 *
 * Keeping them apart is the point. A single widened fact set would mean one
 * mistake in one condition could put last month's revenue in front of a guest,
 * and the conditions for the two are not merely different strictnesses of the
 * same rule — they are about different people.
 */
enum AgentAudience: string
{
    case Guest = 'guest';

    case Operator = 'operator';

    /**
     * Whether an answer to this audience could ever be sent on its own.
     *
     * Only a guest answer can, because only a guest answer goes anywhere. An
     * operator's answer is for the person who asked for it; there is no second
     * party for it to reach unread, so the auto-send gates have nothing to
     * decide and reporting a "held" reason for one would be theatre.
     */
    public function isSendable(): bool
    {
        return $this === self::Guest;
    }

    public function label(): string
    {
        return match ($this) {
            self::Guest => 'a guest',
            self::Operator => 'the property manager',
        };
    }
}
