<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Messaging\Models\Message;
use Illuminate\Database\Eloquent\Model;

/**
 * A guest, owner or vendor wrote to us.
 *
 * Carries the conversation as well as the message because almost every
 * consumer — automation, the unanswered-thread report, the notifier — cares
 * about the thread's state rather than the one line that arrived.
 */
class MessageReceived extends AbstractDomainEvent
{
    public const NAME = 'message.received';

    public function __construct(public readonly Message $message)
    {
        parent::__construct();
    }

    public function subject(): ?Model
    {
        return $this->message;
    }

    public function payload(): array
    {
        return MessagePayload::build($this->message);
    }

    /**
     * Inbound messages carry the transport's own identifier where one exists,
     * so a channel redelivering a webhook cannot raise the event twice.
     */
    public function idempotencyKey(): ?string
    {
        return $this->message->external_message_id === null
            ? null
            : sprintf('message.received:%s:%s', $this->message->channel, $this->message->external_message_id);
    }
}
