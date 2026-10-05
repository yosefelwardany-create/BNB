<?php

declare(strict_types=1);

namespace App\Domain\Agents\Jobs;

use App\Domain\Agents\Models\AgentActivity;
use App\Domain\Agents\Services\AutomaticGuestReplies;
use App\Domain\Agents\Services\GuestAgent;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConversationService;
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
                    $this->receipt($message, 'held', 'The thread or agent settings changed while drafting. Nothing was sent.');

                    return;
                }
                if (! $answer->wouldAutoSend || $answer->isSimulated || trim($answer->reply) === '') {
                    $reason = $answer->heldBecause ?? 'A live, nonempty reply is required for automatic delivery.';
                    $this->receipt($message, 'held', $reason);
                    $conversations->addNote($message->conversation, "Automatic reply held for review: {$reason}\n\nDraft:\n{$answer->reply}");

                    return;
                }
                $this->receipt($message, 'sending', 'Delivery is being attempted. Do not retry until its outcome is checked.');
                $reply = $conversations->send($message->conversation, ['body' => $answer->reply, 'transport' => 'channel', 'is_ai_generated' => true,
                    'ai_provider' => $answer->provider, 'author_type' => 'ai', 'approved_by_id' => null,
                    'metadata' => ['automatic_reply_to' => $message->id, 'authorized_by' => $settings['automatic_replies_authorized_by']]]);
                $sent = in_array($reply->status, ['sent', 'delivered'], true) && ($reply->metadata['delivery']['simulated'] ?? true) === false;
                $this->receipt($message, $sent ? 'sent' : 'failed', $sent ? 'Automatic reply sent to the guest.' : 'Automatic delivery failed. Review the outbound message before retrying.', $reply->id);
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

    private function receipt(Message $message, string $status, string $detail, ?string $replyId = null): void
    {
        $message->refresh()->update(['metadata' => [...($message->metadata ?? []), 'automatic_reply' => [
            'status' => $status, 'detail' => $detail, 'reply_id' => $replyId, 'updated_at' => now()->toISOString(),
        ]]]);
        if ($status !== 'sending') {
            AgentActivity::record(['organization_id' => $message->organization_id, 'property_id' => $message->conversation->property_id,
                'conversation_id' => $message->conversation_id, 'kind' => $status === 'sent' ? AgentActivity::KIND_ACTED : ($status === 'held' ? AgentActivity::KIND_HELD : AgentActivity::KIND_FAILED),
                'summary' => $detail, 'detail' => ['inbound_message_id' => $message->id, 'reply_id' => $replyId], 'is_autonomous' => true]);
        }
    }
}
