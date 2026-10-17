<?php

declare(strict_types=1);

namespace App\Domain\Agents\Jobs;

use App\Domain\Agents\DataObjects\AgentAnswer;
use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Models\AgentActivity;
use App\Domain\Agents\Services\AutomaticGuestReplies;
use App\Domain\Agents\Services\GuestAgent;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConversationService;
use App\Domain\Notifications\Services\Notifier;
use App\Domain\Organization\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** One delivery attempt, with a durable claim before any model or channel call. */
class ReplyToGuestAutomatically implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public string $messageId, public string $organizationId, public string $enabledSince)
    {
        $this->onQueue(config('pms.queues.ai', 'ai'));
    }

    public function handle(TenantContext $tenancy, AutomaticGuestReplies $automation, GuestAgent $agent, ConversationService $conversations): void
    {
        $organization = $tenancy->withoutScope(fn () => Organization::findOrFail($this->organizationId));
        $tenancy->runAs($organization, function () use ($automation, $agent, $conversations): void {
            $message = Message::with(['conversation.property', 'conversation.reservation', 'conversation.guest'])->findOrFail($this->messageId);
            $lock = Cache::lock('automatic-guest-reply:'.$message->conversation_id, 200);
            if (! $lock->get()) {
                $this->release(210);

                return;
            }
            try {
                if (! $automation->eligible($message, $this->enabledSince)) {
                    return;
                }
                $claimed = DB::transaction(function () use ($message): bool {
                    $row = Message::whereKey($message->id)->lockForUpdate()->firstOrFail();
                    if (isset($row->metadata['automatic_reply'])) {
                        return false;
                    }
                    $row->update(['metadata' => [...($row->metadata ?? []), 'automatic_reply' => ['status' => 'processing', 'started_at' => now()->toISOString()]]]);

                    return true;
                });
                if (! $claimed) {
                    return;
                }
                $property = $message->conversation->property;
                $settings = $property->settings['agent'] ?? [];
                $destination = $message->conversation->only(['property_id', 'channel_account_id', 'channel', 'external_thread_id']);
                $history = $message->conversation->messages()->reorder()->where('is_internal_note', false)->whereKeyNot($message->id)
                    ->orderByRaw('COALESCE(sent_at, created_at) DESC')->orderByDesc('id')->limit(20)->get()->reverse()
                    ->map(fn ($m) => ['role' => $m->direction === Message::INBOUND ? 'guest' : 'host', 'body' => $m->body])->values()->all();
                $answer = $agent->answer(property: $property, question: $message->body, reservation: $message->conversation->reservation,
                    history: $history, guestName: $message->conversation->guest?->fullName());
                $message->refresh()->load(['conversation.property', 'conversation.reservation']);
                if (! $automation->eligible($message, $this->enabledSince) || $settings !== ($message->conversation->property->settings['agent'] ?? [])
                    || $destination !== $message->conversation->only(['property_id', 'channel_account_id', 'channel', 'external_thread_id'])) {
                    $this->receipt($message, 'held', 'The thread or agent settings changed while drafting. Nothing was sent.', answer: $answer);

                    return;
                }
                if (! $answer->wouldAutoSend || $answer->isSimulated || trim($answer->reply) === '') {
                    $reason = $answer->heldBecause ?? 'A live, nonempty reply is required for automatic delivery.';
                    $this->receipt($message, 'held', $reason, answer: $answer);
                    $conversations->addNote($message->conversation, "Automatic reply held for review: {$reason}\n\nDraft:\n{$answer->reply}");

                    return;
                }
                $this->receipt($message, 'sending', 'Delivery is being attempted. Do not retry until its outcome is checked.', answer: $answer);
                $reply = $conversations->send($message->conversation, ['body' => $answer->reply, 'transport' => 'channel', 'is_ai_generated' => true,
                    'ai_provider' => $answer->provider, 'author_type' => 'ai', 'approved_by_id' => null,
                    'metadata' => ['automatic_reply_to' => $message->id, 'authorized_by' => $settings['automatic_replies_authorized_by']]]);
                $sent = in_array($reply->status, ['sent', 'delivered'], true) && ($reply->metadata['delivery']['simulated'] ?? true) === false;
                $this->receipt($message, $sent ? 'sent' : 'failed', $sent ? 'Automatic reply sent to the guest.' : 'Automatic delivery failed. Review the outbound message before retrying.', $reply->id, $answer);
            } catch (Throwable $e) {
                $this->receipt($message, 'failed', 'Automatic reply failed: '.Str::limit($e->getMessage(), 400).'. Check the thread before retrying; no automatic retry will occur.');
            } finally {
                $lock->release();
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        $tenancy = app(TenantContext::class);
        $organization = $tenancy->withoutScope(fn () => Organization::find($this->organizationId));
        if ($organization !== null) {
            $tenancy->runAs($organization, function (): void {
                $message = Message::with('conversation')->find($this->messageId);
                if ($message !== null && in_array($message->metadata['automatic_reply']['status'] ?? null, ['processing', 'sending'], true)) {
                    $this->receipt($message, 'failed', 'Automatic reply interrupted. Check the channel before retrying because delivery may have occurred.');
                }
            });
        }
    }

    private function receipt(Message $message, string $status, string $detail, ?string $replyId = null, ?AgentAnswer $answer = null): void
    {
        /*
         * A failure belongs in the thread, not only in a log.
         *
         * A held reply already writes an internal note with its draft and its
         * reason. A failed one wrote nothing anybody would see — so an agent
         * that could not reach a model at all produced exactly the same thing
         * as an agent with nothing to say: an unanswered guest.
         *
         * That happened. Every gate passed, the agent tried to answer, and the
         * worker had no API key. Finding out took a shell command on a
         * production server. It should have been the first line under the
         * message.
         */
        if ($status === 'failed' && $message->conversation !== null) {
            app(ConversationService::class)->addNote(
                $message->conversation,
                "The agent could not answer this automatically.\n\n{$detail}",
            );
        }

        $message->refresh()->update(['metadata' => [...($message->metadata ?? []), 'automatic_reply' => [
            'status' => $status, 'detail' => $detail, 'reply_id' => $replyId, 'updated_at' => now()->toISOString(),
        ]]]);
        if ($status !== 'sending') {
            AgentActivity::record(['organization_id' => $message->organization_id, 'property_id' => $message->conversation->property_id,
                'conversation_id' => $message->conversation_id, 'kind' => $status === 'sent' ? AgentActivity::KIND_ACTED : ($status === 'held' ? AgentActivity::KIND_HELD : AgentActivity::KIND_FAILED),
                'summary' => $detail, 'detail' => $this->what($message, $status, $replyId, $answer), 'is_autonomous' => true]);

            $this->tell($message, $status, $detail);
        }
    }

    /**
     * Everything needed to review this attempt later, in columns.
     *
     * The activity row used to hold two ids. That is enough to prove something
     * happened and not enough to judge it: a reply that went out at 51%
     * confidence and one that went out at 98% were the same row, and a held
     * draft existed only inside the prose of an internal note, where no query
     * can reach it.
     *
     * So the agent's own reading of the question is kept here — what it thought
     * it was being asked, how sure it was, what it refused to say, and which
     * provider answered. `draft` is stored for a held reply because that is the
     * text a person is being asked to approve, and asking somebody to review
     * something they have to go and find is how a review queue stops being
     * read.
     *
     * Older rows have none of this. A reader of this log gets whichever
     * columns existed when the row was written, which is the honest outcome;
     * nothing is backfilled with a guess.
     *
     * @return array<string, mixed>
     */
    private function what(Message $message, string $status, ?string $replyId, ?AgentAnswer $answer): array
    {
        return array_filter([
            'inbound_message_id' => $message->id,
            'reply_id' => $replyId,
            'intent' => $answer?->intent,
            'confidence' => $answer === null ? null : round($answer->confidence, 2),
            'provider' => $answer?->provider,
            'model' => $answer?->model,
            'withheld' => $answer === null || $answer->withheld === [] ? null : $answer->withheld,
            'draft' => $status === 'held' ? $answer?->reply : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Tell the people who run the place what the agent just did.
     *
     * The activity log above is the record; this is the interruption. They are
     * not the same thing and the difference matters: nobody reads a log, and
     * until now an agent could answer a guest, or decline to, and the only
     * trace was a row somebody would have to go looking for. A manager watching
     * an empty inbox had no way to tell an agent that had handled everything
     * from one that had quietly handled nothing.
     *
     * Three kinds, and they deserve different volumes:
     *
     *  - **Held** is the one worth stopping for. The agent met a question it
     *    could not answer from what it knows, and the fix is a sentence typed
     *    into the property's knowledge base. Carries the guest's question and
     *    the reason, so that sentence can be written straight from the bell.
     *  - **Failed** is louder still: a guest is waiting and the channel refused.
     *  - **Sent** is the routine one. It goes out at low priority and under its
     *    own type, so somebody drowning in them can switch that off without
     *    also losing the two that need a person.
     */
    private function tell(Message $message, string $status, string $detail): void
    {
        $conversation = $message->conversation;
        $property = $conversation->property;

        if ($property === null) {
            return;
        }

        $agent = AgentBrief::fromSettings($property->settings)->botName ?? 'The agent';
        $guest = $conversation->guest?->display_name ?? 'a guest';
        $asked = Str::limit(trim((string) $message->body), 160);

        [$type, $title, $priority, $icon] = match ($status) {
            'sent' => ['agent.replied', "{$agent} replied to {$guest}", 'low', 'bot'],
            'held' => ['agent.held', "{$agent} was not sure how to answer {$guest}", 'normal', 'help-circle'],
            default => ['agent.failed', "{$agent} could not deliver a reply to {$guest}", 'high', 'alert-triangle'],
        };

        app(Notifier::class)->notifyPermissionHolders(
            organizationId: $message->organization_id,
            permission: 'messages.view',
            type: $type,
            title: $title,
            body: $status === 'sent' ? $asked : "{$asked}\n\n{$detail}",
            subject: $conversation,
            url: '/inbox?conversation='.$conversation->getKey(),
            priority: $priority,
            icon: $icon,
            /*
             * Enough for the bell to offer the repair without another round
             * trip. A held reply is a gap in what the property has written
             * down, and `question` is the gap stated in the guest's own words —
             * which is the topic somebody should be filling in.
             */
            data: [
                'property_id' => $property->getKey(),
                'property_name' => $property->name,
                'conversation_id' => $conversation->getKey(),
                'message_id' => $message->getKey(),
                'question' => $asked,
                'reason' => $detail,
                'status' => $status,
            ],
        );
    }
}
