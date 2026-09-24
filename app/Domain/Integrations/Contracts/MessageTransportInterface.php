<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Contracts;

use App\Domain\Integrations\DataObjects\DeliveryResult;
use App\Domain\Integrations\DataObjects\OutboundMessage;

/**
 * How a message leaves the platform.
 *
 * A conversation is transport-agnostic — the same thread can carry a message
 * that arrived through a channel's inbox and a reply sent by email — so the
 * decision of *how* to deliver belongs here rather than in the messaging
 * domain. The messaging service composes the message; the transport delivers
 * it and says honestly what happened.
 *
 * `isLive()` is not decoration. It flows through to the message record, the
 * inbox and the settings screen, so an operator can always tell whether a
 * guest actually received something or whether it was recorded locally.
 */
interface MessageTransportInterface
{
    /** Registry key: `email`, `local`, `channel`. */
    public function key(): string;

    public function displayName(): string;

    /**
     * Whether a message handed to this transport reaches a real recipient.
     *
     * This is evaluated against current configuration rather than hard-coded,
     * because the same transport can be live or not depending on how it is
     * set up — an email transport pointed at the log driver delivers nothing.
     */
    public function isLive(): bool;

    /**
     * Why the transport is not live, when it is not. Shown verbatim in the
     * settings screen so the remedy is never a guess.
     */
    public function simulationReason(): ?string;

    /**
     * Whether this transport can address the message at all: an email
     * transport needs an address, a channel transport needs a thread.
     */
    public function canDeliver(OutboundMessage $message): bool;

    /**
     * Why this transport cannot carry this particular message. Null when it
     * can.
     *
     * Separate from `simulationReason()`, which is about configuration. This
     * is about one message, and it is what the fallback records: "the email
     * transport could not address this recipient" sends somebody looking in
     * the wrong place when the real answer is that the property is not mapped
     * on the channel the guest wrote from.
     */
    public function undeliverableReason(OutboundMessage $message): ?string;

    public function send(OutboundMessage $message): DeliveryResult;
}
