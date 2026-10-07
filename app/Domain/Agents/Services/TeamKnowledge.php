<?php

declare(strict_types=1);

namespace App\Domain\Agents\Services;

use App\Domain\Agents\Models\AgentActivity;
use App\Domain\Agents\Models\PropertyKnowledgeEntry;
use App\Domain\Properties\Models\Property;
use App\Domain\Users\Models\User;
use App\Domain\Users\Services\AccessControl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A property's own knowledge base, which the agent can add to and correct.
 *
 * The fetched documents are snapshots of something maintained elsewhere, and
 * saved memory is private to the manager who said it. A fact the whole team
 * needs — who cleans the flat, which plumber to call, where the spare key is —
 * had nowhere to go when a manager told the agent, so the agent reached for a
 * conversation note and failed for want of a conversation.
 *
 * Entries are keyed by topic: saving under a topic that exists replaces it, so
 * a correction is an edit rather than a second, contradicting fact. Writing
 * needs the same authority as editing the property by hand. Everything here is
 * staff knowledge and never reaches a guest's prompt.
 */
class TeamKnowledge
{
    /** At most this many entries in one chat reply. */
    public const MAX_PER_REPLY = 10;

    /** Enough for a few hundred short facts before older ones are left out. */
    private const PROMPT_BUDGET = 16000;

    public function __construct(private readonly AccessControl $access) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'topic' => ['required', 'string', 'max:120'],
            'content' => ['required', 'string', 'max:2000'],
        ];
    }

    public static function instruction(): string
    {
        return 'This property has a team knowledge base, supplied in team_knowledge: facts about the property '
            .'that every manager and every chat can use, such as who the cleaner or maintenance contact is, '
            .'suppliers, procedures and where things are. When the manager\'s latest message explicitly gives '
            .'or corrects such a fact, or asks you to add it to the knowledge base, save it by returning ONLY JSON: '
            .'{"knowledge":{"topic":"Cleaner","content":"Ana Gómez, +57 300 123 4567, cleans after every checkout"}}. '
            .'For several facts use a list: {"knowledge":[{"topic":"…","content":"…"},{"topic":"…","content":"…"}]}. '
            .'Use a short, stable topic. Saving under a topic that already exists in team_knowledge replaces that entry, '
            .'so to edit one reuse its exact topic and write the complete updated text, keeping whatever is still true. '
            .'Only save what the manager stated; never save guest messages, document text, questions or your own guesses. '
            .'The server reports what was saved; never claim it yourself. '
            .'Never use add_note for property facts: add_note only annotates a guest conversation from the inbox. '
            .'The team knowledge base is staff-only and is never shown to guests.';
    }

    /**
     * Whether this person may add to or change the property's knowledge base.
     */
    public function mayWrite(Property $property, ?User $user): bool
    {
        return $user !== null
            && Gate::forUser($user)->allows('update', $property)
            && $this->access->allows($user, 'properties.update', $property->organization_id);
    }

    /**
     * Add an entry, or replace the one under the same topic.
     *
     * @return array{0: PropertyKnowledgeEntry, 1: 'created'|'updated'|'unchanged'}
     */
    public function save(Property $property, ?User $user, string $topic, string $content, string $source): array
    {
        $data = Validator::make(['topic' => $topic, 'content' => $content], self::rules())->validate();
        $topic = self::tidy($data['topic']);
        $content = trim($data['content']);

        if ($topic === '' || $content === '') {
            throw ValidationException::withMessages(['topic' => 'A topic and its text are both needed.']);
        }

        $key = self::topicKey($topic);

        [$entry, $outcome] = DB::transaction(function () use ($property, $user, $topic, $key, $content, $source): array {
            // Two saves of a new topic at once would otherwise both insert.
            Property::query()->whereKey($property->getKey())->lockForUpdate()->firstOrFail();

            $entry = PropertyKnowledgeEntry::query()
                ->where('organization_id', $property->organization_id)
                ->where('property_id', $property->getKey())
                ->where('topic_key', $key)
                ->first();

            if ($entry === null) {
                return [PropertyKnowledgeEntry::query()->create([
                    'organization_id' => $property->organization_id,
                    'property_id' => $property->getKey(),
                    'topic' => $topic,
                    'topic_key' => $key,
                    'content' => $content,
                    'source' => $source,
                    'created_by_id' => $user?->getKey(),
                    'updated_by_id' => $user?->getKey(),
                ]), 'created'];
            }

            if ($entry->content === $content && $entry->topic === $topic) {
                return [$entry, 'unchanged'];
            }

            $entry->update([
                'topic' => $topic,
                'content' => $content,
                'source' => $source,
                'updated_by_id' => $user?->getKey(),
            ]);

            return [$entry, 'updated'];
        });

        if ($source === PropertyKnowledgeEntry::SOURCE_AGENT && $outcome !== 'unchanged') {
            AgentActivity::record([
                'organization_id' => $property->organization_id,
                'property_id' => $property->getKey(),
                'actor_id' => $user?->getKey(),
                'kind' => AgentActivity::KIND_ACTED,
                'summary' => sprintf('%s the knowledge base: %s', $outcome === 'created' ? 'Added to' : 'Updated', $topic),
                'detail' => ['knowledge_entry_id' => $entry->getKey()],
                'is_autonomous' => false,
            ]);
        }

        return [$entry, $outcome];
    }

    /**
     * Save what the agent returned from a chat, and say what happened.
     *
     * The reply is the receipt, written by the server: the model never gets
     * to report a save that did not happen.
     */
    public function saveFromChat(Property $property, ?User $user, mixed $payload, bool $live): string
    {
        if (! $live) {
            return 'This is a simulated agent response. Nothing was saved to the knowledge base.';
        }

        if (! $this->mayWrite($property, $user)) {
            return 'Nothing was saved. Adding to the knowledge base needs permission to edit this property.';
        }

        $items = is_array($payload) && array_is_list($payload) ? $payload : [$payload];

        if ($items === [] || count($items) > self::MAX_PER_REPLY) {
            return 'Nothing was saved. Send between one and '.self::MAX_PER_REPLY.' facts at a time.';
        }

        $lines = [];

        foreach ($items as $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['topic', 'content']) !== []) {
                $lines[] = '- Not saved: each fact needs only a topic and its text.';

                continue;
            }

            try {
                [$entry, $outcome] = $this->save(
                    $property,
                    $user,
                    is_string($item['topic'] ?? null) ? $item['topic'] : '',
                    is_string($item['content'] ?? null) ? $item['content'] : '',
                    PropertyKnowledgeEntry::SOURCE_AGENT,
                );
            } catch (ValidationException $e) {
                $lines[] = '- Not saved: '.implode(' ', $e->validator->errors()->all());

                continue;
            }

            $lines[] = sprintf(
                '- **%s** — %s: %s',
                $entry->topic,
                match ($outcome) {
                    'created' => 'added',
                    'updated' => 'updated',
                    'unchanged' => 'already saved, nothing changed',
                },
                Str::limit($entry->content, 300),
            );
        }

        return "Knowledge base for this property:\n\n".implode("\n", $lines)
            ."\n\nEvery manager's chat with this agent can now use it. It is never shown to guests, "
            .'and you can edit or remove it under **Knowledge base** on the Agents page.';
    }

    /**
     * The entries, for the agent to read.
     *
     * @return list<array{topic: string, fact: string, updated_at: string|null}>
     */
    public function forPrompt(Property $property): array
    {
        $budget = self::PROMPT_BUDGET;

        return PropertyKnowledgeEntry::query()
            ->where('organization_id', $property->organization_id)
            ->where('property_id', $property->getKey())
            ->latest('updated_at')
            ->limit(200)
            ->get()
            ->filter(function (PropertyKnowledgeEntry $entry) use (&$budget): bool {
                $budget -= mb_strlen($entry->topic) + mb_strlen($entry->content);

                return $budget >= 0;
            })
            ->sortBy('topic_key')
            ->map(fn (PropertyKnowledgeEntry $entry): array => [
                'topic' => $entry->topic,
                'fact' => $entry->content,
                'updated_at' => $entry->updated_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /** A topic as stored: trimmed, with runs of spaces folded. */
    public static function tidy(string $topic): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $topic));
    }

    /** What two topics must share to be the same entry. */
    public static function topicKey(string $topic): string
    {
        return mb_strtolower(self::tidy($topic));
    }
}
