<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agents;

use App\Domain\Agents\Services\GuestAgent;
use App\Domain\Messaging\Models\Conversation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Drafting a reply to a real conversation with the property's agent.
 *
 * Separate from sending on purpose. This endpoint needs only `messages.view`
 * because it produces text on a screen; putting that text in front of a guest
 * still goes through the send endpoint and still needs `messages.send`. An agent
 * that could reply because someone opened the inbox would be a permission
 * system with a hole in it.
 *
 * `would_auto_send` says whether the agent judged this safe to send without
 * review. It is reported, never acted on here — automatic sending, when it is
 * built, belongs in a job with the organization's own audit trail behind it, not
 * in a request somebody's browser made.
 */
class ConversationAgentController extends Controller
{
    public function __construct(private readonly GuestAgent $agent) {}

    public function draft(Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $answer = $this->agent->answerConversation($conversation);

        if ($answer === null) {
            // Not an error. A thread with no inbound message, or none attached
            // to a property, is a thing the agent legitimately cannot answer,
            // and saying which is more use than a 422.
            return response()->json([
                'data' => null,
                'reason' => $conversation->property_id === null && $conversation->reservation_id === null
                    ? 'This conversation is not attached to a property, so there is no agent to ask.'
                    : 'There is no guest message on this conversation to answer yet.',
            ]);
        }

        return response()->json([
            'data' => ['answer' => $answer->toArray()],
            'was_sent' => false,
        ]);
    }
}
