<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Agents\Models\AgentActivity;
use App\Domain\Messaging\Models\Message;
use App\Domain\Properties\Models\Property;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * What the agents have actually said to guests.
 *
 * {@see ExplainAutomaticReply} answers the opposite question — why one message
 * went unanswered — and it was written first because that was the complaint.
 * But the two are not symmetric, and only having the negative one produced a
 * strange blindness: an operator could find out precisely why each silence
 * happened while having no way at all to read what the agent said when it did
 * speak. "Is this thing working?" is not answerable by a list of failures.
 *
 * Three outcomes, and keeping them apart is the whole point:
 *
 *  - **Reached a guest.** A real channel accepted it. This is the only one that
 *    means a guest read something.
 *  - **Simulated.** Habitat wrote a message and nothing left the building. It
 *    is listed separately rather than counted as sent, because a simulated
 *    send with a green status is exactly how a demo lies.
 *  - **Held or failed.** No message exists. These come from the activity log.
 *
 * It also separates what the agent sent on its own from what it drafted for a
 * person who then sent it. Both are AI-written text a guest received, so both
 * belong here; they carry very different weight when the question is how much
 * the thing is being trusted.
 *
 * Read-only. Safe to run against production while a guest is waiting.
 */
class ShowAgentReplies extends Command
{
    protected $signature = 'agents:replies
        {--days=7 : How far back to look}
        {--property= : Only this property, by name or id}
        {--sent : Only replies that reached a guest}
        {--held : Only drafts waiting for a person}
        {--failed : Only attempts that broke}
        {--full : Print replies whole instead of the first lines}
        {--limit=40 : Most recent this many}';

    protected $description = 'Show what the agents have sent to guests, and what they held or failed to send';

    public function handle(TenantContext $tenancy): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $since = CarbonImmutable::now()->subDays($days);

        /*
         * Across every tenant. A console command has no current organization,
         * and the global scope would quietly return an empty list — which here
         * would read as "the agent has never said anything", the most
         * misleading possible answer.
         */
        return $tenancy->withoutScope(function () use ($since, $days, $limit): int {
            $property = $this->property();

            if ($property === false) {
                return self::FAILURE;
            }

            $entries = $this->delivered($since, $limit, $property)
                ->concat($this->withheld($since, $limit, $property))
                ->sortByDesc(fn (array $entry): string => $entry['at']->toISOString())
                ->values();

            $wanted = $this->wanted();

            if ($wanted !== []) {
                $entries = $entries
                    ->filter(fn (array $entry): bool => in_array($entry['group'], $wanted, true))
                    ->values();
            }

            $this->newLine();
            $this->line(sprintf(
                '<options=bold>What the agents said to guests</> — last %d day%s%s',
                $days,
                $days === 1 ? '' : 's',
                $property instanceof Property ? ' · '.$property->name : ' · every property',
            ));
            $this->newLine();

            $this->tally($entries);

            if ($entries->isEmpty()) {
                $this->newLine();
                $this->line('  Nothing in this window. Widen it with --days=30, or run');
                $this->line('  <options=bold>agents:why-no-reply "<guest name>"</> to see which check closed on a thread.');
                $this->newLine();

                return self::SUCCESS;
            }

            foreach ([
                'sent' => 'Reached a guest',
                'simulated' => 'Written but never sent — the channel was simulated',
                'held' => 'Held for a person to read',
                'failed' => 'Failed',
            ] as $group => $heading) {
                $rows = $entries->where('group', $group)->take($limit);

                if ($rows->isEmpty()) {
                    continue;
                }

                $this->newLine();
                $this->line("<options=bold>── {$heading} ".str_repeat('─', max(1, 62 - strlen($heading))).'</>');

                foreach ($rows as $entry) {
                    $this->entry($entry);
                }
            }

            $this->newLine();

            return self::SUCCESS;
        });
    }

    /**
     * The property filter, or false when the name matched nothing.
     *
     * False rather than null, because "no filter" and "a filter that found
     * nothing" must not produce the same listing. Printing every property's
     * replies under a heading naming the one that was asked for would be worse
     * than an error.
     */
    private function property(): Property|false|null
    {
        $needle = $this->option('property');

        if (! is_string($needle) || trim($needle) === '') {
            return null;
        }

        $property = Property::query()
            ->where('id', $needle)
            ->orWhere('name', 'ilike', '%'.$needle.'%')
            ->first();

        if ($property === null) {
            $this->error("No property matches \"{$needle}\".");
            $this->newLine();
            $this->line('Properties with an agent configured:');

            foreach (Property::query()->orderBy('name')->limit(25)->get() as $candidate) {
                $enabled = (bool) ($candidate->settings['agent']['enabled'] ?? false);
                $this->line(sprintf('  %s  %s', $enabled ? '<fg=green>on </>' : '<fg=gray>off</>', $candidate->name));
            }

            return false;
        }

        return $property;
    }

    /** @return list<string> */
    private function wanted(): array
    {
        $groups = [];

        if ($this->option('sent')) {
            $groups[] = 'sent';
        }

        if ($this->option('held')) {
            $groups[] = 'held';
        }

        if ($this->option('failed')) {
            $groups[] = 'failed';
            $groups[] = 'simulated';
        }

        return $groups;
    }

    /**
     * Everything the agent wrote that became a real outbound message.
     *
     * Driven from `messages` rather than the activity log, because the activity
     * log records automatic replies only. An agent draft that a person read and
     * sent is still a guest reading AI-written text, and leaving it out would
     * understate how much of the voice of the business is already automated.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function delivered(CarbonImmutable $since, int $limit, Property|null|false $property): Collection
    {
        $replies = Message::query()
            ->with(['conversation.property', 'conversation.guest', 'approvedBy'])
            ->where('is_ai_generated', true)
            ->where('direction', Message::OUTBOUND)
            ->where('is_internal_note', false)
            ->whereRaw('COALESCE(sent_at, created_at) >= ?', [$since])
            /*
             * Guest threads only. An owner thread can hold AI-written text too
             * — a person may log one by hand — and listing it under a heading
             * that says a guest read it would be false. The header says this is
             * guest threads, so nobody takes the count for the whole picture.
             */
            ->whereHas('conversation', fn ($conversation) => $conversation->where('participant_type', 'guest'))
            ->when($property instanceof Property, fn ($query) => $query->whereHas(
                'conversation',
                fn ($conversation) => $conversation->where('property_id', $property->getKey()),
            ))
            ->orderByRaw('COALESCE(sent_at, created_at) DESC')
            ->limit($limit * 2)
            ->get();

        $questions = $this->questions($replies);

        return $replies->map(function (Message $reply) use ($questions): array {
            $delivery = $reply->metadata['delivery'] ?? [];
            $landed = in_array($reply->status, ['sent', 'delivered', 'read'], true);

            /*
             * A draft somebody copied into Airbnb themselves reached the guest.
             * It has no delivery metadata, because no transport of ours ran —
             * and treating a missing transport verdict as a simulation would
             * file the one case where a human definitely did the work under
             * "nothing left the building".
             */
            $byHand = ($reply->metadata['delivered_by_hand'] ?? false) === true;
            $simulated = ! $byHand && ($delivery['simulated'] ?? true) !== false;

            $answered = $questions->get($reply->metadata['automatic_reply_to'] ?? '')
                ?? $this->preceding($reply, $questions);

            return [
                'group' => match (true) {
                    $landed && ! $simulated => 'sent',
                    $landed => 'simulated',
                    default => 'failed',
                },
                'at' => CarbonImmutable::parse($reply->sent_at ?? $reply->created_at),
                'conversation' => $reply->conversation,
                'question' => $answered?->body,
                'said' => $reply->body,
                /*
                 * `authorized_by` is the membership that ticked the switch, not
                 * a person who read this particular reply — so it is never
                 * reported as approval. Unattended means unattended.
                 */
                'by' => match (true) {
                    isset($reply->metadata['automatic_reply_to']) => 'on its own',
                    $byHand => 'drafted by the agent, carried out by hand'.($reply->approvedBy === null ? '' : ' by '.$reply->approvedBy->name),
                    $reply->approvedBy === null => 'drafted by the agent, sent without a recorded approver',
                    default => 'drafted by the agent, sent by '.$reply->approvedBy->name,
                },
                'why' => match (true) {
                    ! $landed => $reply->failure_reason ?? 'The channel did not accept it.',
                    $simulated => (string) ($delivery['reason'] ?? 'No live channel was connected, so nothing left Habitat.'),
                    default => null,
                },
                'provider' => $reply->ai_provider,
                'intent' => null,
                'confidence' => null,
                'withheld' => [],
            ];
        });
    }

    /**
     * The attempts that never became a message: held drafts and hard failures.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function withheld(CarbonImmutable $since, int $limit, Property|null|false $property): Collection
    {
        $activities = AgentActivity::query()
            ->with(['conversation.property', 'conversation.guest'])
            ->autonomous()
            ->whereIn('kind', [AgentActivity::KIND_HELD, AgentActivity::KIND_FAILED])
            ->where('occurred_at', '>=', $since)
            ->when($property instanceof Property, fn ($query) => $query->where('property_id', $property->getKey()))
            ->orderByDesc('occurred_at')
            ->limit($limit * 2)
            ->get();

        $asked = Message::query()
            ->whereKey($activities->map(fn (AgentActivity $a): ?string => $a->detail['inbound_message_id'] ?? null)->filter()->unique())
            ->get()
            ->keyBy('id');

        return $activities
            /*
             * A failure that got as far as creating an outbound message is
             * already listed from `messages`, with its delivery metadata. Two
             * rows for one attempt would read as two guests unanswered.
             */
            ->reject(fn (AgentActivity $activity): bool => ($activity->detail['reply_id'] ?? null) !== null)
            ->map(fn (AgentActivity $activity): array => [
                'group' => $activity->kind === AgentActivity::KIND_HELD ? 'held' : 'failed',
                'at' => CarbonImmutable::parse($activity->occurred_at),
                'conversation' => $activity->conversation,
                'question' => $asked->get($activity->detail['inbound_message_id'] ?? '')?->body,
                /*
                 * Only rows written after the draft started being recorded have
                 * one. For the rest this stays null and the entry says where
                 * the draft is, rather than inventing a reconstruction of it.
                 */
                'said' => $activity->detail['draft'] ?? null,
                'by' => 'on its own',
                'why' => $activity->summary,
                'provider' => $activity->detail['provider'] ?? null,
                'intent' => $activity->detail['intent'] ?? null,
                'confidence' => $activity->detail['confidence'] ?? null,
                'withheld' => $activity->detail['withheld'] ?? [],
            ])
            ->values();
    }

    /**
     * The inbound messages these replies were answering.
     *
     * @param  Collection<int, Message>  $replies
     * @return Collection<string, Message>
     */
    private function questions(Collection $replies): Collection
    {
        if ($replies->isEmpty()) {
            return new Collection;
        }

        $direct = $replies->map(fn (Message $m): ?string => $m->metadata['automatic_reply_to'] ?? null)->filter()->unique();

        /*
         * Plus every inbound message in the threads involved, so a reply a
         * person sent — which carries no pointer back to a question — can still
         * be shown next to the message immediately before it. One query for the
         * lot rather than one per reply.
         */
        return Message::query()
            ->where('is_internal_note', false)
            ->where(fn ($query) => $query
                ->whereKey($direct)
                ->orWhere(fn ($inbound) => $inbound
                    ->where('direction', Message::INBOUND)
                    ->whereIn('conversation_id', $replies->pluck('conversation_id')->unique())))
            ->get()
            ->keyBy('id');
    }

    /**
     * The last thing the guest said before this reply went out.
     *
     * @param  Collection<string, Message>  $questions
     */
    private function preceding(Message $reply, Collection $questions): ?Message
    {
        $at = $reply->sent_at ?? $reply->created_at;

        return $questions
            ->filter(fn (Message $m): bool => $m->conversation_id === $reply->conversation_id
                && $m->direction === Message::INBOUND
                && ($m->sent_at ?? $m->created_at) <= $at)
            ->sortByDesc(fn (Message $m): string => ($m->sent_at ?? $m->created_at)->toISOString())
            ->first();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     */
    private function tally(Collection $entries): void
    {
        $sent = $entries->where('group', 'sent');
        $alone = $sent->where('by', 'on its own')->count();

        foreach ([
            [$sent->count(), 'reached a guest', 'green',
                $sent->isEmpty() ? '' : sprintf('%d on its own, %d a person sent', $alone, $sent->count() - $alone)],
            [$entries->where('group', 'simulated')->count(), 'written but never sent', 'yellow', 'no live channel — nothing reached anybody'],
            [$entries->where('group', 'held')->count(), 'held for a person', 'yellow', 'each one is a gap in what the property has written down'],
            [$entries->where('group', 'failed')->count(), 'failed', 'red', 'a guest is waiting'],
        ] as [$count, $label, $colour, $note]) {
            if ($count === 0) {
                continue;
            }

            $this->line(sprintf(
                '  <fg=%s;options=bold>%3d</>  %-26s %s',
                $colour,
                $count,
                $label,
                $note === '' ? '' : "<fg=gray>{$note}</>",
            ));
        }

        if ($entries->isEmpty()) {
            $this->line('  <fg=gray>  0  the agents have said nothing in this window</>');
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function entry(array $entry): void
    {
        $conversation = $entry['conversation'];

        $this->newLine();
        $this->line(sprintf(
            '  <options=bold>%s</>  %s · %s%s',
            $entry['at']->toDateTimeString(),
            $conversation?->property?->name ?? 'unknown property',
            $conversation?->guest?->display_name ?? $conversation?->displayTitle() ?? 'unknown guest',
            $conversation?->channel ? ' · '.$conversation->channel : '',
        ));

        if ($entry['question'] !== null) {
            $this->wrapped('guest', (string) $entry['question'], 'cyan');
        }

        if ($entry['said'] !== null) {
            $this->wrapped($entry['group'] === 'held' ? 'draft' : 'agent', (string) $entry['said'], 'default');
        } elseif ($entry['group'] === 'held') {
            $this->wrapped('draft', 'On the thread as an internal note — this attempt predates drafts being recorded here.', 'gray');
        }

        if ($entry['why'] !== null) {
            $this->wrapped($entry['group'] === 'sent' ? 'note' : 'why', (string) $entry['why'], 'yellow');
        }

        $footnotes = array_filter([
            $entry['by'],
            $entry['intent'] !== null ? 'read as '.$entry['intent'] : null,
            $entry['confidence'] !== null ? ((int) round(((float) $entry['confidence']) * 100)).'% sure' : null,
            $entry['provider'] !== null ? 'via '.$entry['provider'] : null,
            $entry['withheld'] !== [] ? 'would not say: '.implode(', ', (array) $entry['withheld']) : null,
        ]);

        $this->line('            <fg=gray>'.implode(' · ', $footnotes).'</>');
        $this->line('            <fg=gray>/inbox?conversation='.($conversation?->getKey() ?? '').'</>');
    }

    private function wrapped(string $label, string $body, string $colour): void
    {
        $body = trim(preg_replace('/\s*\n\s*/', "\n", $body) ?? $body);

        if (! $this->option('full')) {
            $body = Str::limit($body, 400);
        }

        $lines = [];

        foreach (explode("\n", $body) as $paragraph) {
            foreach (explode("\n", wordwrap($paragraph, 68, "\n", false)) as $line) {
                $lines[] = $line;
            }
        }

        foreach ($lines as $index => $line) {
            $open = $colour === 'default' ? '' : "<fg={$colour}>";
            $close = $colour === 'default' ? '' : '</>';

            $this->line(sprintf('    %-6s  %s%s%s', $index === 0 ? $label : '', $open, $line, $close));
        }
    }
}
