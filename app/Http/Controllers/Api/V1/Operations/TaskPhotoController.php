<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Operations;

use App\Domain\Operations\Models\Task;
use App\Domain\Operations\Models\TaskChecklistItem;
use App\Domain\Operations\Models\TaskPhoto;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskPhotoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photographic evidence attached to a task.
 *
 * Before-and-after photographs of a turnover are what settle a damage dispute
 * with a guest or an owner, so `stage` is a first-class field rather than a
 * caption convention, and a checklist item can require one before it may be
 * marked done.
 */
class TaskPhotoController extends Controller
{
    public function store(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        $data = $request->validate([
            'photo' => ['required', 'file', 'image', 'max:12288'],
            'checklist_item_id' => ['sometimes', 'nullable', 'string', 'exists:task_checklist_items,id'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stage' => ['sometimes', 'nullable', Rule::in(['before', 'during', 'after', 'damage'])],
        ]);

        if (isset($data['checklist_item_id'])) {
            // `exists` would accept an item belonging to another task, which
            // would let a cleaner satisfy someone else's photo requirement.
            $item = TaskChecklistItem::query()->findOrFail($data['checklist_item_id']);

            abort_unless($item->task_id === $task->getKey(), 422, 'That checklist item belongs to another task.');
        }

        $file = $request->file('photo');
        $disk = (string) config('filesystems.default', 'local');

        $path = $file->store(
            sprintf('organizations/%s/tasks/%s', $task->organization_id, $task->getKey()),
            $disk,
        );

        $photo = TaskPhoto::query()->create([
            'organization_id' => $task->organization_id,
            'task_id' => $task->getKey(),
            'checklist_item_id' => $data['checklist_item_id'] ?? null,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'caption' => $data['caption'] ?? null,
            'stage' => $data['stage'] ?? null,
            'uploaded_by_id' => $this->currentUser()->getKey(),
        ]);

        return (new TaskPhotoResource($photo))->response()->setStatusCode(201);
    }

    /**
     * Stream a photo.
     *
     * Used where the disk cannot issue a temporary signed URL — the local
     * driver in development, for one — so that the storage path never has to
     * be exposed and the tenant check still applies to every read.
     */
    public function show(TaskPhoto $photo): StreamedResponse
    {
        $task = $photo->task;

        abort_if($task === null, 404);

        $this->authorize('view', $task);

        $disk = Storage::disk($photo->disk);

        abort_unless($disk->exists($photo->path), 404);

        return $disk->response($photo->path);
    }

    public function destroy(Task $task, TaskPhoto $photo): JsonResponse
    {
        $this->authorize('update', $task);

        abort_unless($photo->task_id === $task->getKey(), 404);

        // A photo attached to a completed checklist item is part of the record
        // of what was found; removing it would undo evidence somebody may be
        // relying on.
        if ($photo->checklist_item_id !== null && $task->status->isTerminal()) {
            abort(422, 'Photographs on a finished task are part of its record and cannot be removed.');
        }

        Storage::disk($photo->disk)->delete($photo->path);
        $photo->delete();

        return response()->json(['message' => 'The photograph has been removed.']);
    }
}
