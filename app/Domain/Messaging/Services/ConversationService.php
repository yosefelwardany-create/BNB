<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Messaging\Events\MessageReceived;
use App\Domain\Messaging\Events\MessageSent;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Reservations\Models\Reservation;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The unified inbox.
 *
 * Two decisions shape this service.
 *
 * **Thread summary fields are maintained on write, not computed on read.**
 * "Which guests are waiting for a reply, longest first" is the inbox's primary
 * sort. Deriving it would mean aggregating every message in the organization on
 * every page load, so `last_inbound_at`, `unread_count` and the response-time
 * fields are updated as each message lands. The cost is a few columns to keep
 * honest; the alternative is an inbox that gets slower as the business grows.
 *
 * **Response time is measured from the guest's first unanswered message.**
 * Not from the thread's creation — a thread we started ourselves was never
 * waiting on us — and not from the most recent inbound, which would let a
 * long-ignored guest look promptly answered the moment they chased.
 */
class ConversationService
{
    public function __construct(
        private readonly MessageDispatcher $dispatcher,
        private readonly TemplateRenderer $renderer,
        private readonly TenantContext $tenancy,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The thread for a reservation, opening one if this is the first contact.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function forReservation(Reservation $reservation, array $attributes = []): Conversation
    {
        $existing = Conversation::query()
            ->where('reservation_id', $reservation->getKey())
            ->where('participant_type', 'guest')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->open([
            'reservation_id' => $reservation->getKey(),
            'guest_id' => $reservation->guest_id,
            'property_id' => $reservation->property_id,
            'listing_id' => $reservation->listing_id,
            'participant_type' => 'guest',
            'subject' => sprintf('Booking %s', $reservation->confirmation_code),
            'channel' => $reservation->source,
        ] + $attributes);
    }

    /**
     * Open a thread.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function open(array $attributes): Conversation
    {
        $organization = $this->tenancy->organizationOrFail();

        $conversation = new Conversation;
        $conversation->fill($attributes);
        $conversation->organization_id = $organization->getKey();

        try {
            // Its own transaction, so that catching the violation below is
            // safe: PostgreSQL aborts the entire transaction on any error, and
            // recovering from one raised directly inside a caller's transaction
            // would leave that transaction unusable. Nested, this is a
            // savepoint and the rollback is scoped to the failed insert.
            DB::transaction(fn () => $conversation->save());
        } catch (QueryException $exception) {
            // A channel redelivering the first message of a thread races here.
            // The unique index on (organization, channel, external_thread_id)
            // is what makes the outcome one thread rather than two.
            if ($this->isUniqueViolation($exception) && isset($attributes['external_thread_id'])) {
                return Conversation::query()
                    ->where('channel', $attributes['channel'] ?? null)
                    ->where('external_thread_id', $attributes['external_thread_id'])
                    ->firstOrFail();
            }

            throw $exception;
        }

        return $conversation;
    }

    /**
     * Record something that arrived from a guest, owner or vendor.
     *
     * Inbound messages are recorded, never dispatched: they have already
     * travelled. A thread that had been archived is reopened, because a guest
     * writing again is the definition of the thread not being finished.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordInbound(Conversation $conversation, array $attributes): Message
    {
        return DB::transaction(function () use ($conversation, $attributes): Message {
            // `??=`, not `+`: a caller passing an explicit null means "you
            // decide", and array union would keep the null because the key is
            // present. The transport column is NOT NULL, so that difference is
            // the difference between a message and a constraint violation.
            $attributes['direction'] ??= Message::INBOUND;
            $attributes['author_type'] ??= $conversation->participant_type === 'owner' ? 'owner' : 'guest';
            $attributes['author_name'] ??= $conversation->guest?->fullName() ?? $conversation->owner?->display_name;
            $attributes['transport'] ??= $conversation->channel !== null ? 'channel' : 'email';
            $attributes['status'] ??= 'delivered';

            $message = $this->write($conversation, $attributes);

            $now = $message->created_at ?? now();

            $updates = [
                'messages_count' => $conversation->messages_count + 1,
                'unread_count' => $conversation->unread_count + 1,
                'last_message_at' => $now,
                'last_message_preview' => $message->preview(250),
                'last_message_direction' => Message::INBOUND,
                'last_inbound_at' => $now,
            ];

            // A guest writing again revives a thread somebody had filed away.
            if (in_array($conversation->status, [Conversation::STATUS_ARCHIVED, Conversation::STATUS_CLOSED], true)) {
                $updates['status'] = Conversation::STATUS_OPEN;
                $updates['archived_at'] = null;
            }

            // A snooze is a decision to look later; a new message overtakes it.
            if ($conversation->status === Conversation::STATUS_SNOOZED) {
                $updates['status'] = Conversation::STATUS_OPEN;
                $updates['snoozed_until'] = null;
            }

            $conversation->forceFill($updates)->save();

            MessageReceived::dispatch($message);

            return $message;
        });
    }

    /**
     * Send a message to the other party.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function send(Conversation $conversation, array $attributes): Message
    {
        $message = DB::transaction(function () use ($conversation, $attributes): Message {
            $attributes['direction'] ??= Message::OUTBOUND;
            $attributes['author_type'] ??= 'user';
            $attributes['user_id'] ??= auth()->id();
            $attributes['status'] ??= 'queued';

            // A thread that came from a channel can only be replied to through
            // that channel's own inbox, and only while we hold its thread id.
            // Without one there is nothing to reply into, so email it is — and
            // the dispatcher says so on the message if that fails too.
            $attributes['transport'] ??= $conversation->channel !== null
                && $conversation->external_thread_id !== null
                    ? 'channel'
                    : 'email';

            $message = $this->write($conversation, $attributes);

            $this->recordOutboundOnThread($conversation, $message);

            return $message;
        });

        // Delivery happens after the transaction commits: a transport failure
        // must not roll back the record that we tried, and a slow SMTP server
        // must not hold a database transaction open.
        $this->dispatcher->dispatch($message);

        MessageSent::dispatch($message->refresh());

        return $message;
    }

    /**
     * Add a note visible to colleagues only.
     *
     * Kept in the same thread rather than a separate side-channel: the context
     * of "guest asked for a late checkout" and "the cleaner can't do it before
     * 1pm" is the same context, and separating them loses it.
     */
    public function addNote(Conversation $conversation, string $body): Message
    {
        return DB::transaction(function () use ($conversation, $body): Message {
            $message = $this->write($conversation, [
                'direction' => Message::INTERNAL,
                'transport' => 'internal',
                'body' => $body,
                'author_type' => 'user',
                'user_id' => auth()->id(),
                'is_internal_note' => true,
                'status' => 'sent',
            ]);

            // A note is not a reply: it does not touch the response clock, and
            // a thread with only notes on it is still a waiting guest.
            $conversation->forceFill([
                'messages_count' => $conversation->messages_count + 1,
                'last_message_at' => $message->created_at ?? now(),
            ])->save();

            return $message;
        });
    }

    /**
     * Send a template, rendered against the thread's reservation.
     *
     * @param  array<string, mixed>  $context
     */
    public function sendTemplate(
        Conversation $conversation,
        MessageTemplate $template,
        array $context = [],
        ?string $automationRuleId = null,
    ): Message {
        $rendered = $this->renderForConversation($conversation, $template, $context);

        return $this->send($conversation, [
            'body' => $rendered['body'],
            'subject' => $rendered['subject'],
            'template_id' => $template->getKey(),
            'automation_rule_id' => $automationRuleId,
            'author_type' => $automationRuleId !== null ? 'system' : 'user',
            'language' => $rendered['language'],
            'transport' => $template->transport === 'channel' && $conversation->external_thread_id === null
                ? null // fall through to the dispatcher's default
                : $template->transport,
        ]);
    }

    /**
     * Render a template against a thread without sending it.
     *
     * Used by the composer's preview and by the automation rule editor, so
     * what an operator sees before sending is produced by the same code that
     * sends it.
     *
     * @param  array<string, mixed>  $context
     * @return array{subject: string|null, body: string, language: string}
     */
    public function renderForConversation(
        Conversation $conversation,
        MessageTemplate $template,
        array $context = [],
    ): array {
        $reservation = $conversation->reservation;
        $guest = $conversation->guest;

        // The guest's own language wins, falling back to the template's.
        $localised = $template->forLanguage($guest?->language);

        return [
            'subject' => $localised->subject === null
                ? null
                : $this->renderer->render($localised->subject, $reservation, $guest, $conversation->property, $context),
            'body' => $this->renderer->render(
                $localised->body,
                $reservation,
                $guest,
                $conversation->property,
                $context,
            ),
            'language' => $localised->language,
        ];
    }

    /**
     * Put a thread in somebody's name.
     */
    public function assign(Conversation $conversation, ?string $userId, ?string $teamId = null): Conversation
    {
        $previous = ['assigned_to_id' => $conversation->assigned_to_id, 'team_id' => $conversation->team_id];

        $conversation->forceFill([
            'assigned_to_id' => $userId,
            'team_id' => $teamId,
        ])->save();

        $this->audit->record(
            action: 'conversation.assigned',
            subject: $conversation,
            oldValues: $previous,
            newValues: ['assigned_to_id' => $userId, 'team_id' => $teamId],
        );

        return $conversation;
    }

    /**
     * Clear the unread marker. Reading is not replying, so the response clock
     * is untouched.
     */
    public function markRead(Conversation $conversation): Conversation
    {
        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }

        return $conversation;
    }

    /**
     * Hide a thread until a moment, then bring it back.
     */
    public function snooze(Conversation $conversation, CarbonImmutable $until): Conversation
    {
        $conversation->forceFill([
            'status' => Conversation::STATUS_SNOOZED,
            'snoozed_until' => $until,
        ])->save();

        return $conversation;
    }

    /**
     * File a thread away. Nothing is deleted: an archived conversation is
     * still searchable, still attached to its reservation, and still reopens
     * the moment the guest writes again.
     */
    public function archive(Conversation $conversation): Conversation
    {
        $conversation->forceFill([
            'status' => Conversation::STATUS_ARCHIVED,
            'archived_at' => now(),
            'unread_count' => 0,
        ])->save();

        return $conversation;
    }

    public function close(Conversation $conversation): Conversation
    {
        $conversation->forceFill([
            'status' => Conversation::STATUS_CLOSED,
            'archived_at' => now(),
            'unread_count' => 0,
        ])->save();

        return $conversation;
    }

    public function reopen(Conversation $conversation): Conversation
    {
        $conversation->forceFill([
            'status' => Conversation::STATUS_OPEN,
            'archived_at' => null,
            'snoozed_until' => null,
        ])->save();

        return $conversation;
    }

    /**
     * Threads whose snooze has expired, so the scheduler can surface them.
     *
     * @return int how many were woken
     */
    public function wakeSnoozed(): int
    {
        return Conversation::query()
            ->where('status', Conversation::STATUS_SNOOZED)
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now())
            ->update(['status' => Conversation::STATUS_OPEN, 'snoozed_until' => null]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Persist one message against a thread.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function write(Conversation $conversation, array $attributes): Message
    {
        $message = new Message;

        $message->fill(collect($attributes)->only((new Message)->getFillable())->all());

        $message->organization_id = $conversation->organization_id;
        $message->conversation_id = $conversation->getKey();
        $message->channel ??= $conversation->channel;
        $message->language ??= $conversation->guest?->language;

        $message->save();

        return $message;
    }

    /**
     * Update the thread's summary after we replied.
     */
    private function recordOutboundOnThread(Conversation $conversation, Message $message): void
    {
        $now = $message->created_at ?? now();

        $updates = [
            'messages_count' => $conversation->messages_count + 1,
            'last_message_at' => $now,
            'last_message_preview' => $message->preview(250),
            'last_message_direction' => Message::OUTBOUND,
            'last_outbound_at' => $now,
        ];

        // The first reply to a waiting guest sets the response time, once.
        // Recording it again on the second reply would flatter the number.
        if ($conversation->first_response_at === null && $conversation->last_inbound_at !== null) {
            $updates['first_response_at'] = $now;
            $updates['first_response_minutes'] = max(
                0,
                (int) $conversation->last_inbound_at->diffInMinutes($now),
            );
        }

        $conversation->forceFill($updates)->save();
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23505', '23000'], true);
    }
}
