<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Agents\Jobs\ReplyToGuestAutomatically;
use App\Domain\Integrations\Registries\ChannelAdapterRegistry;
use App\Domain\Messaging\Models\Message;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

class AutomaticGuestReplies
{
    public function schedule(Message $message): void
    {
        $message->loadMissing('conversation.property');
        $since = $message->conversation?->property?->settings['agent']['automatic_replies_since'] ?? null;
        if (is_string($since) && $this->eligible($message, $since)) {
            ReplyToGuestAutomatically::dispatch($message->id, $message->organization_id, $since)->afterCommit();
        }
    }

    public function eligible(Message $message, string $since): bool
    {
        $conversation = $message->conversation;
        $property = $conversation?->property;
        if ($property === null || $property->organization_id !== $message->organization_id
            || $conversation->organization_id !== $message->organization_id
            || $conversation->participant_type !== 'guest' || $conversation->status !== 'open'
            || $message->direction !== Message::INBOUND || $message->author_type !== 'guest'
            || $message->is_internal_note || $message->transport !== 'channel'
            || ($message->metadata['logged_by_hand'] ?? false) || empty($conversation->external_thread_id)) {
            return false;
        }
        $brief = AgentBrief::fromSettings($property->settings);
        $settings = $property->settings['agent'] ?? [];
        if (! $brief->enabled || ! $brief->automaticGuestReplies || $brief->autoSend === []
            || ! in_array('send_message', $brief->mayDo, true)
            || ($settings['automatic_replies_since'] ?? null) !== $since
            || ($message->sent_at ?? $message->created_at)->lessThan(CarbonImmutable::parse($since))) {
            return false;
        }
        $actor = User::find($settings['automatic_replies_authorized_by'] ?? null);
        app(AccessControl::class)->flushMemo();
        if ($actor === null || ! $actor->isActive() || ! Gate::forUser($actor)->allows('update', $property)
            || ! app(AccessControl::class)->allows($actor, 'messages.send', $property->organization_id)) {
            return false;
        }
        $adapters = app(ChannelAdapterRegistry::class);
        if (! $adapters->has((string) $conversation->channel) || ! $adapters->make($conversation->channel)->isLive()) {
            return false;
        }
        // Source timestamps matter when a pull imports both sides of a thread.
        $latest = $conversation->messages()->reorder()->where('is_internal_note', false)
            ->orderByRaw('COALESCE(sent_at, created_at) DESC')->orderByDesc('id')->first();

        return $latest?->id === $message->id;
    }
}
