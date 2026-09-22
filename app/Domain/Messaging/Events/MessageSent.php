<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Events;

use App\Domain\Events\Support\AbstractDomainEvent;
use App\Domain\Messaging\Models\Message;
use Illuminate\Database\Eloquent\Model;

/**
 * We wrote to a guest, an owner or a vendor.
 *
 * Raised for messages a person sent and for messages automation sent; the
 * payload's `author_type` and `automation_rule_id` distinguish them, which is
 * what lets a report answer "how much of our guest communication is automated?"
 */
class MessageSent extends AbstractDomainEvent
{
    public const NAME = 'message.sent';

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
        return MessagePayload::build($this->message) + [
            'automation_rule_id' => $this->message->automation_rule_id,
            'template_id' => $this->message->template_id,
        ];
    }
}
