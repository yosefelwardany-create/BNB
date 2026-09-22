<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Domain\Messaging\Models\SavedReply;
use App\Http\Controllers\Controller;
use App\Http\Resources\SavedReplyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Canned replies an agent reaches for mid-conversation.
 *
 * Kept apart from templates deliberately: templates are what automation sends
 * unattended, saved replies are what a person picks and reads before sending.
 * Merging them would let an agent's shorthand become an automated guest
 * message.
 */
class SavedReplyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SavedReply::class);

        $query = SavedReply::query();

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->toString().'%';

            $query->where(function ($q) use ($term): void {
                $q->where('name', 'ilike', $term)
                    ->orWhere('shortcut', 'ilike', $term)
                    ->orWhere('body', 'ilike', $term);
            });
        }

        // Most-used first: the composer's picker should put what people
        // actually reach for at the top.
        return SavedReplyResource::collection(
            $query->orderByDesc('times_used')->orderBy('name')->paginate($this->perPage()),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', SavedReply::class);

        $reply = new SavedReply;
        $reply->fill($request->validate($this->rules()));
        $reply->organization_id = $this->organization()->getKey();
        $reply->created_by_id = $this->currentUser()->getKey();
        $reply->save();

        return (new SavedReplyResource($reply))->response()->setStatusCode(201);
    }

    public function update(Request $request, SavedReply $savedReply): SavedReplyResource
    {
        $this->authorize('update', $savedReply);

        $savedReply->fill($request->validate($this->rules($savedReply)))->save();

        return new SavedReplyResource($savedReply->fresh());
    }

    public function destroy(SavedReply $savedReply): JsonResponse
    {
        $this->authorize('delete', $savedReply);

        // Nothing references a saved reply once it has been used — the text
        // was copied into the message — so this one really can be removed.
        $savedReply->delete();

        return response()->json(['message' => 'The saved reply has been removed.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?SavedReply $reply = null): array
    {
        $creating = $reply === null;

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'shortcut' => ['sometimes', 'nullable', 'string', 'max:32'],
            'body' => [$creating ? 'required' : 'sometimes', 'string', 'max:10000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:48'],
        ];
    }
}
