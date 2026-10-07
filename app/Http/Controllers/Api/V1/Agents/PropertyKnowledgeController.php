<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agents;

use App\Domain\Agents\Models\PropertyKnowledgeEntry;
use App\Domain\Agents\Services\TeamKnowledge;
use App\Domain\Properties\Models\Property;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A property's own knowledge base, by hand.
 *
 * The agent writes to the same entries from the chat; this is where people
 * read them, correct them and take them out. Readable by anybody who may see
 * the property, like the helper list beside it on the screen.
 */
class PropertyKnowledgeController extends Controller
{
    public function __construct(private readonly TeamKnowledge $knowledge) {}

    public function index(Property $property): JsonResponse
    {
        $this->authorize('view', $property);

        $entries = PropertyKnowledgeEntry::query()
            ->where('property_id', $property->getKey())
            ->with(['updatedBy:id,first_name,last_name,email'])
            ->orderBy('topic_key')
            ->get();

        return response()->json(['data' => $entries->map(fn (PropertyKnowledgeEntry $entry): array => $this->present($entry))->all()]);
    }

    public function store(Request $request, Property $property): JsonResponse
    {
        $this->authorize('update', $property);

        $data = $request->validate(TeamKnowledge::rules());

        [$entry, $outcome] = $this->knowledge->save(
            $property,
            $request->user(),
            $data['topic'],
            $data['content'],
            PropertyKnowledgeEntry::SOURCE_MANUAL,
        );

        return response()->json(['data' => $this->present($entry->load('updatedBy')), 'meta' => ['outcome' => $outcome]], $outcome === 'created' ? 201 : 200);
    }

    public function update(Request $request, Property $property, string $entry): JsonResponse
    {
        $this->authorize('update', $property);

        $current = $this->find($property, $entry);
        $data = $request->validate(TeamKnowledge::rules());
        $topic = TeamKnowledge::tidy($data['topic']);
        $content = trim($data['content']);
        abort_if($topic === '' || $content === '', 422, 'A topic and its text are both needed.');

        // Renaming onto another entry's topic would silently merge two facts.
        $clash = PropertyKnowledgeEntry::query()
            ->where('property_id', $property->getKey())
            ->where('topic_key', TeamKnowledge::topicKey($topic))
            ->whereKeyNot($current->getKey())
            ->exists();
        abort_if($clash, 422, 'Another entry already uses that topic. Edit that one instead.');

        $current->update([
            'topic' => $topic,
            'topic_key' => TeamKnowledge::topicKey($topic),
            'content' => $content,
            'source' => PropertyKnowledgeEntry::SOURCE_MANUAL,
            'updated_by_id' => $request->user()?->getKey(),
        ]);

        return response()->json(['data' => $this->present($current->load('updatedBy'))]);
    }

    public function destroy(Property $property, string $entry): JsonResponse
    {
        $this->authorize('update', $property);

        $this->find($property, $entry)->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function find(Property $property, string $id): PropertyKnowledgeEntry
    {
        return PropertyKnowledgeEntry::query()
            ->where('property_id', $property->getKey())
            ->whereKey($id)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PropertyKnowledgeEntry $entry): array
    {
        $by = $entry->updatedBy;

        return [
            'id' => $entry->getKey(),
            'topic' => $entry->topic,
            'content' => $entry->content,
            'source' => $entry->source,
            'updated_by' => $by === null ? null : (trim(($by->first_name ?? '').' '.($by->last_name ?? '')) ?: $by->email),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
    }
}
