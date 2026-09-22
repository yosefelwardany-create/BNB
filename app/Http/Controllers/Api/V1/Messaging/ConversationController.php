<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Messaging\Models\SavedReply;
use App\Domain\Messaging\Services\ConversationService;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Users\Services\AccessControl;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The unified inbox.
 *
 * The default listing is "what needs me": open threads, guests waiting
 * longest first. That ordering is the product's opinion about what an inbox is
 * for, and it is expressed here rather than left to each client to reinvent.
 */
class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly AccessControl $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Conversation::class);

        $query = Conversation::query()->with(['guest', 'property', 'assignee']);

        $restricted = $this->access->restrictedPropertyIds($this->currentUser());

        if ($restricted !== null) {
            // A general enquiry attached to no property is visible to anyone
            // who can read the inbox; anything about a property is not.
            $query->where(function ($q) use ($restricted): void {
                $q->whereNull('property_id')->orWhereIn('property_id', $restricted);
            });
        }

        match ($request->string('filter', 'inbox')->toString()) {
            'all' => null,
            'unread' => $query->unread(),
            'awaiting_reply' => $query->inbox()->awaitingReply(),
            'mine' => $query->inbox()->assignedTo($this->currentUser()->getKey()),
            'unassigned' => $query->inbox()->whereNull('assigned_to_id'),
            'archived' => $query->whereIn('status', [Conversation::STATUS_ARCHIVED, Conversation::STATUS_CLOSED]),
            default => $query->inbox(),
        };

        if ($request->filled('property_id')) {
            $query->where('property_id', $request->string('property_id')->toString());
        }

        if ($request->filled('reservation_id')) {
            $query->where('reservation_id', $request->string('reservation_id')->toString());
        }

        if ($request->filled('guest_id')) {
            $query->where('guest_id', $request->string('guest_id')->toString());
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';

            $query->where(function ($q) use ($term): void {
                $q->where('subject', 'ilike', $term)
                    ->orWhere('last_message_preview', 'ilike', $term);
            });
        }

        // Guests who have been waiting longest come first. A thread nobody is
        // waiting on sorts by recency instead, which is what "last_inbound_at
        // nulls last" achieves in one pass.
        match ($request->string('sort', 'waiting')->toString()) {
            'recent' => $query->orderByDesc('last_message_at'),
            'oldest' => $query->orderBy('last_message_at'),
            default => $query
                ->orderByRaw('CASE WHEN last_outbound_at IS NULL OR last_inbound_at > last_outbound_at THEN 0 ELSE 1 END')
                ->orderByRaw('last_inbound_at ASC NULLS LAST')
                ->orderByDesc('last_message_at'),
        };

        return ConversationResource::collection($query->paginate($this->perPage()));
    }

    /**
     * Counts for the inbox's own navigation, in one call.
     */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', Conversation::class);

        $userId = $this->currentUser()->getKey();

        return response()->json([
            'data' => [
                'inbox' => Conversation::query()->inbox()->count(),
                'unread' => Conversation::query()->unread()->count(),
                'awaiting_reply' => Conversation::query()->inbox()->awaitingReply()->count(),
                'mine' => Conversation::query()->inbox()->assignedTo($userId)->count(),
                'unassigned' => Conversation::query()->inbox()->whereNull('assigned_to_id')->count(),

                // The number an operations manager actually watches: how long
                // the guest who has waited longest has been waiting.
                'longest_wait_minutes' => Conversation::query()
                    ->inbox()
                    ->awaitingReply()
                    ->orderBy('last_inbound_at')
                    ->first()
                    ?->minutesWaiting(),
            ],
        ]);
    }

    public function show(Conversation $conversation): ConversationResource
    {
        $this->authorize('view', $conversation);

        // Opening a thread clears its unread marker. Reading is not replying,
        // so the response clock is untouched.
        $this->conversations->markRead($conversation);

        return new ConversationResource($conversation->load([
            'guest', 'property', 'reservation', 'assignee', 'messages.user',
        ]));
    }

    /**
     * Open a thread, or return the one that already exists for a reservation.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Conversation::class);

        $data = $request->validate([
            'reservation_id' => ['sometimes', 'nullable', 'string', 'exists:reservations,id'],
            'guest_id' => ['sometimes', 'nullable', 'string', 'exists:guests,id'],
            'owner_id' => ['sometimes', 'nullable', 'string', 'exists:owners,id'],
            'property_id' => ['sometimes', 'nullable', 'string', 'exists:properties,id'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'participant_type' => ['sometimes', Rule::in(['guest', 'owner', 'vendor', 'internal'])],
        ]);

        if (isset($data['reservation_id'])) {
            $reservation = Reservation::query()->findOrFail($data['reservation_id']);

            $conversation = $this->conversations->forReservation($reservation);

            return response()->json([
                'data' => (new ConversationResource($conversation->load(['guest', 'property'])))->resolve(),
            ], $conversation->wasRecentlyCreated ? 201 : 200);
        }

        $conversation = $this->conversations->open($data);

        return (new ConversationResource($conversation))->response()->setStatusCode(201);
    }

    /**
     * Reply to the other party.
     */
    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('send', $conversation);

        $data = $request->validate([
            'body' => ['required_without:template_id', 'nullable', 'string', 'max:20000'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'template_id' => ['sometimes', 'nullable', 'string', 'exists:message_templates,id'],
            'saved_reply_id' => ['sometimes', 'nullable', 'string', 'exists:saved_replies,id'],
            'transport' => ['sometimes', 'nullable', 'string', 'max:24'],
            'attachments' => ['sometimes', 'array'],
        ]);

        if (isset($data['template_id'])) {
            $template = MessageTemplate::query()->findOrFail($data['template_id']);

            $message = $this->conversations->sendTemplate($conversation, $template);
        } else {
            if (isset($data['saved_reply_id'])) {
                SavedReply::query()->find($data['saved_reply_id'])?->recordUse();
            }

            $message = $this->conversations->send($conversation, [
                'body' => $data['body'],
                'subject' => $data['subject'] ?? null,
                'transport' => $data['transport'] ?? null,
                'attachments' => $data['attachments'] ?? null,
            ]);
        }

        $delivery = $message->metadata['delivery'] ?? [];

        return response()->json([
            'data' => (new MessageResource($message))->resolve(),

            // Said plainly rather than buried in metadata: an operator pressing
            // send deserves to know at once if nothing actually left.
            'notice' => ($delivery['simulated'] ?? false)
                ? ($delivery['reason'] ?? 'This message was recorded but not delivered.')
                : null,
        ], 201);
    }

    /**
     * Add a note visible to colleagues only.
     */
    public function addNote(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('note', $conversation);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $message = $this->conversations->addNote($conversation, $data['body']);

        return (new MessageResource($message))->response()->setStatusCode(201);
    }

    /**
     * Preview a template against this thread without sending it.
     *
     * Rendered by the same code that sends, so what an operator approves is
     * what the guest receives.
     */
    public function previewTemplate(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $data = $request->validate([
            'template_id' => ['required', 'string', 'exists:message_templates,id'],
        ]);

        $template = MessageTemplate::query()->findOrFail($data['template_id']);

        return response()->json([
            'data' => $this->conversations->renderForConversation($conversation, $template),
        ]);
    }

    public function assign(Request $request, Conversation $conversation): ConversationResource
    {
        $this->authorize('assign', $conversation);

        $data = $request->validate([
            'assigned_to_id' => ['sometimes', 'nullable', 'string', 'exists:users,id'],
            'team_id' => ['sometimes', 'nullable', 'string', 'exists:teams,id'],
        ]);

        $conversation = $this->conversations->assign(
            $conversation,
            $data['assigned_to_id'] ?? null,
            $data['team_id'] ?? null,
        );

        return new ConversationResource($conversation->fresh(['assignee']));
    }

    /**
     * Snooze, archive, close or reopen.
     *
     * Nothing here deletes: an archived thread is still searchable, still
     * attached to its reservation, and reopens the moment the guest writes.
     */
    public function transition(Request $request, Conversation $conversation, string $action): ConversationResource
    {
        $this->authorize('manage', $conversation);

        $conversation = match ($action) {
            'snooze' => $this->conversations->snooze(
                $conversation,
                CarbonImmutable::parse($request->validate([
                    'until' => ['required', 'date', 'after:now'],
                ])['until']),
            ),
            'archive' => $this->conversations->archive($conversation),
            'close' => $this->conversations->close($conversation),
            'reopen' => $this->conversations->reopen($conversation),
            'read' => $this->conversations->markRead($conversation),
            default => abort(404),
        };

        return new ConversationResource($conversation->fresh());
    }
}
