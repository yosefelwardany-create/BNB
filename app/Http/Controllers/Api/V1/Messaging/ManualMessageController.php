<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\ConversationService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Logging messages that travelled outside this platform.
 *
 * Most operators cannot reach Airbnb or Booking.com programmatically — the APIs
 * are behind partner agreements, and a channel manager is a monthly bill. Until
 * one of those is in place, the guest conversation happens in somebody else's
 * inbox, and this platform is blind to it: no response times, no thread for the
 * agent to read, no history when a dispute arrives eleven months later.
 *
 * So the work is done by hand. A person pastes in what the guest wrote, the
 * property's agent drafts a reply from that property's facts, and the person
 * copies it back. Clerical, and completely legitimate — which is more than can
 * be said for a scraper driving somebody's Airbnb account.
 *
 * Two rules hold everything here together:
 *
 *  - **Nothing is sent.** Both actions only write history. The reply left
 *    through a person's hands before this endpoint heard about it.
 *  - **The record says where it really went.** `transport` carries the truth —
 *    `manual` when nobody names anything better — and the response is explicit
 *    that this platform delivered nothing. A thread that showed "sent" for a
 *    message we merely heard about would be the same lie as a simulated channel
 *    reporting a successful push.
 */
class ManualMessageController extends Controller
{
    public function __construct(private readonly ConversationService $conversations) {}

    /**
     * Log a message a guest sent somewhere else.
     *
     * Needs only `messages.view`: this is data entry about something that has
     * already happened, not an act of reaching a guest.
     */
    public function received(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:20000'],
            // Where the guest actually wrote. Free text rather than an enum,
            // because the list of places a guest can reach an operator is not
            // ours to close.
            'transport' => ['sometimes', 'string', 'max:40'],
            // When they wrote, if it was not just now. A thread pasted in an
            // hour late should not read as an hour-late guest.
            'received_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $message = $this->conversations->recordInbound($conversation, [
            'body' => $validated['body'],
            'transport' => $validated['transport'] ?? 'manual',
            'status' => 'delivered',
            'metadata' => ['logged_by_hand' => true],
        ]);

        if (isset($validated['received_at'])) {
            // Backdated after the write so the thread bookkeeping still runs
            // against a real message, then corrected to when it truly arrived.
            $message->forceFill(['created_at' => $validated['received_at']])->save();
        }

        return (new MessageResource($message->fresh()))
            ->additional(['meta' => ['logged_by_hand' => true, 'was_sent_by_us' => false]])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Log a reply a person already delivered somewhere else.
     *
     * Needs `messages.send`, because it asserts that a guest was contacted in
     * the organization's name. Nothing leaves here — but the thread, the
     * response clock and every report drawn from them will believe it, so the
     * claim deserves the same permission as making it true.
     */
    public function delivered(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:20000'],
            'transport' => ['sometimes', 'string', 'max:40'],
            'sent_at' => ['sometimes', 'nullable', 'date'],
            // True when the text came from the property's agent. Recorded so a
            // manager can still tell what a model drafted after it has been
            // carried out of here by hand.
            'is_ai_generated' => ['sometimes', 'boolean'],
        ]);

        $message = $this->conversations->recordDeliveredElsewhere($conversation, [
            'body' => $validated['body'],
            'transport' => $validated['transport'] ?? 'manual',
            'sent_at' => $validated['sent_at'] ?? null,
            'delivered_at' => $validated['sent_at'] ?? null,
            'is_ai_generated' => $validated['is_ai_generated'] ?? false,
            'approved_by_id' => $this->currentUser()->getKey(),
            'metadata' => ['delivered_by_hand' => true],
        ]);

        return (new MessageResource($message->fresh()))
            ->additional(['meta' => ['delivered_by_hand' => true, 'was_sent_by_us' => false]])
            ->response()
            ->setStatusCode(201);
    }
}
