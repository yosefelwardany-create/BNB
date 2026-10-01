<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Agents\Models\AgentAsk;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One question put to a bot that answers later, as a screen needs it.
 *
 * The fields that matter most are the ones about waiting. An ask with no answer
 * yet has to render as visibly pending — with when it went out and when it gives
 * up — rather than as an empty answer or as nothing having happened, which is
 * the way an asynchronous feature usually starts lying to people.
 *
 * `withheld` and `sent_fact_keys` are here because they are how an operator
 * judges an answer: a bot that was never told the door code answering a question
 * about the door code is behaving correctly, and that only reads correctly if
 * the screen can say what it had to work with.
 *
 * @mixin AgentAsk
 */
class AgentAskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AgentAsk $ask */
        $ask = $this->resource;

        return [
            'id' => $ask->getKey(),
            'property_id' => $ask->property_id,
            'reservation_id' => $ask->reservation_id,
            'status' => $ask->status,
            'question' => $ask->question,
            'guest_name' => $ask->guest_name,

            'bot_name' => $ask->bot_name,
            'endpoint_host' => $ask->endpoint_host,

            'asked_at' => $ask->created_at?->toIso8601String(),
            'dispatched_at' => $ask->dispatched_at?->toIso8601String(),
            'expires_at' => $ask->expires_at?->toIso8601String(),
            'answered_at' => $ask->answered_at?->toIso8601String(),
            // Computed rather than left to the client to work out from two
            // timestamps and a status, so every screen agrees about it.
            'is_waiting' => $ask->isOpen(),

            'reply' => $ask->reply,
            'intent' => $ask->intent,
            'confidence' => $ask->confidence,
            'would_auto_send' => (bool) $ask->would_auto_send,
            'held_because' => $ask->held_because,
            'failure' => $ask->failure,

            'withheld' => $ask->withheld ?? [],
            'used_facts' => $ask->sent_fact_keys ?? [],

            // The same flag every other answer in this platform carries. An ask
            // produces a draft and nothing else: no message has gone anywhere.
            'was_sent' => false,
        ];
    }
}
