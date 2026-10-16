<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Integrations\Registries\ChannelAdapterRegistry;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Why a guest message was not answered on its own.
 *
 * Automatic replies pass eighteen checks before anything reaches a guest, and
 * every one of them is there because the alternative is worse: a door code sent
 * to the wrong thread, a price quoted by a model that was guessing, a reply that
 * looked sent and was only simulated. The checks are right.
 *
 * What was missing is any way to see *which* one closed. From the inbox a held
 * reply and a reply that never ran look identical — an unanswered message — so
 * the only available conclusion was "the feature doesn't work", which sends
 * somebody hunting through settings that were never the problem. That happened:
 * automatic replies were switched on, two cancellation messages went unanswered,
 * and the reasonable inference was that the switch did nothing. In fact a
 * cancellation is a booking change, which is never sent unattended whatever is
 * ticked, and both messages predated the switch.
 *
 * So this prints the whole ladder, pass and fail, for the newest message in a
 * thread. It reads and changes nothing, which is what makes it safe to run
 * against production while a guest is waiting.
 */
class ExplainAutomaticReply extends Command
{
    protected $signature = 'agents:why-no-reply {thread : A conversation id, or part of the guest\'s name}';

    protected $description = 'Show why the newest guest message in a thread was or was not answered automatically';

    public function handle(TenantContext $tenancy, ChannelAdapterRegistry $adapters): int
    {
        $needle = (string) $this->argument('thread');

        // Across every tenant: a command has no current organization, and the
        // global scope would silently match nothing.
        $conversation = $tenancy->withoutScope(fn (): ?Conversation => Conversation::query()
            ->with(['property', 'guest'])
            ->where(fn ($query) => $query
                ->where('id', $needle)
                ->orWhereHas('guest', fn ($guest) => $guest
                    ->where('first_name', 'ilike', "%{$needle}%")
                    ->orWhere('last_name', 'ilike', "%{$needle}%")))
            ->orderByDesc('last_message_at')
            ->first());

        if ($conversation === null) {
            $this->error("No conversation matches \"{$needle}\".");

            return self::FAILURE;
        }

        return $tenancy->withoutScope(function () use ($conversation, $adapters): int {
            $property = $conversation->property;

            if ($property === null) {
                $this->error('This thread is not attached to a property, so no agent owns it.');

                return self::FAILURE;
            }

            $message = $conversation->messages()->reorder()
                ->where('is_internal_note', false)
                ->orderByRaw('COALESCE(sent_at, created_at) DESC')
                ->orderByDesc('id')
                ->first();

            if ($message === null) {
                $this->error('This thread has no guest-visible messages.');

                return self::FAILURE;
            }

            $settings = $property->settings['agent'] ?? [];
            $brief = AgentBrief::fromSettings($property->settings);
            $since = is_string($settings['automatic_replies_since'] ?? null)
                ? $settings['automatic_replies_since']
                : null;

            $this->line("Thread    {$conversation->id}");
            $this->line("Property  {$property->name}");
            $this->line('Channel   '.($conversation->channel ?: '(none)'));
            $this->line('Newest    '.Str::limit((string) $message->body, 70));
            $this->newLine();

            $this->section('The thread', [
                ['a guest thread', $conversation->participant_type === 'guest', (string) $conversation->participant_type],
                ['open', $conversation->status === 'open', (string) $conversation->status],
                ['has a real channel thread id', ! empty($conversation->external_thread_id),
                    $conversation->external_thread_id ?: 'empty — this thread was started inside Habitat, not by the channel'],
            ]);

            $this->section('The message', [
                ['inbound', $message->direction === Message::INBOUND, (string) $message->direction],
                ['written by the guest', $message->author_type === 'guest', (string) $message->author_type],
                ['arrived through a channel', $message->transport === 'channel',
                    ($message->transport ?: '?').($message->transport === 'channel' ? '' : ' — typed or logged by hand, so never answered automatically')],
                ['not logged by hand', ! ($message->metadata['logged_by_hand'] ?? false), ''],
                ['arrived after the switch was turned on',
                    $since !== null && ($message->sent_at ?? $message->created_at)->greaterThanOrEqualTo(CarbonImmutable::parse($since)),
                    $since === null ? 'automatic replies have never been switched on for this property' : "switched on {$since}"],
            ]);

            $this->section('The agent', [
                ['agent enabled', $brief->enabled, ''],
                ['automatic replies ticked', $brief->automaticGuestReplies, ''],
                ['at least one subject chosen', $brief->autoSend !== [], implode(', ', $brief->autoSend) ?: 'none chosen'],
                ['may send messages', in_array('send_message', $brief->mayDo, true), ''],
            ]);

            $live = $conversation->channel !== null
                && $adapters->has((string) $conversation->channel)
                && $adapters->make($conversation->channel)->isLive();

            $this->section('The channel', [
                ['a channel this platform speaks', $conversation->channel !== null && $adapters->has((string) $conversation->channel), (string) $conversation->channel],
                ['really connected, not simulated', $live, $live ? '' : 'a reply that could only be simulated is held, never sent'],
            ]);

            $this->newLine();
            $this->line('<options=bold>What happened to this message</>');

            $receipt = $message->metadata['automatic_reply'] ?? null;

            if ($receipt === null) {
                $this->line('  No receipt. The job never ran for this message — one of the failures above stopped it being scheduled.');
            } else {
                $this->line("  {$receipt['status']} — ".($receipt['detail'] ?? ''));
            }

            $this->newLine();
            $this->line('<options=bold>Subjects that may ever be answered unattended</>');
            $this->line('  '.implode(', ', AgentBrief::AUTO_SENDABLE));
            $this->line('  Prices, rebooking, cancellations, door codes, payments and complaints are always read by a person,');
            $this->line('  whatever is ticked. A confident wrong answer about money or access is not recoverable with an apology.');
            $this->line("  This property's confidence floor is ".(int) round($brief->confidenceFloor * 100).'%.');

            return self::SUCCESS;
        });
    }

    /**
     * @param  list<array{0: string, 1: bool, 2: string}>  $checks
     */
    private function section(string $title, array $checks): void
    {
        $this->line("<options=bold>{$title}</>");

        foreach ($checks as [$label, $ok, $detail]) {
            $this->line(sprintf(
                '  %s  %-38s %s',
                $ok ? '<fg=green>pass</>' : '<fg=red>FAIL</>',
                $label,
                $detail,
            ));
        }

        $this->newLine();
    }
}
