<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Experience;

use App\Domain\Documents\Models\Document;
use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files attached to things.
 *
 * Two behaviours here are worth stating.
 *
 * **Files are streamed through the application, never linked to directly.**
 * A permanent public URL to a guest's passport scan is a permanent public URL
 * to a guest's passport scan, whatever the folder is called; routing every
 * read through an authorised endpoint is what makes the permission mean
 * anything.
 *
 * **Deletion is real, and that is deliberate.** Almost nothing else in this
 * platform deletes, but holding somebody's identity document past its lawful
 * retention period is a liability rather than a record, and a system that
 * could never delete one would be unable to honour an ordinary request.
 */
class DocumentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Document::class);

        $query = Document::query()->with('uploader');

        if ($request->filled('documentable_type') && $request->filled('documentable_id')) {
            $query->where('documentable_type', $request->string('documentable_type')->toString())
                ->where('documentable_id', $request->string('documentable_id')->toString());
        }

        if ($request->filled('kind')) {
            $query->ofKind($request->string('kind')->toString());
        }

        // The two privacy queues: documents held past their retention date,
        // and certificates that have themselves expired.
        if ($request->boolean('past_retention')) {
            $query->pastRetention();
        }

        if ($request->boolean('expired')) {
            $query->expired();
        }

        $documents = $query->latest()->paginate($this->perPage());

        // Filtered after the query rather than in it, because whether a
        // document is readable depends on its kind and the caller's guest
        // permission — a rule the policy owns and the query should not
        // duplicate.
        $documents->setCollection(
            $documents->getCollection()->filter(
                fn (Document $document): bool => $this->currentUser()->can('view', $document),
            )->values(),
        );

        return DocumentResource::collection($documents);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Document::class);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'name' => ['sometimes', 'string', 'max:255'],
            'kind' => $this->kindRule(),
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'documentable_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'documentable_id' => ['sometimes', 'nullable', 'string', 'size:26'],

            'is_guest_visible' => ['sometimes', 'boolean'],
            'is_owner_visible' => ['sometimes', 'boolean'],
            'contains_personal_data' => ['sometimes', 'boolean'],
            'retention_until' => ['sometimes', 'nullable', 'date', 'after:today'],
            'expires_on' => ['sometimes', 'nullable', 'date'],
        ]);

        $file = $request->file('file');

        $path = $file->store(
            sprintf('documents/%s', $this->organization()->getKey()),
            ['disk' => config('filesystems.default')],
        );

        $document = new Document;
        $document->fill(collect($data)->except('file')->all());
        $document->organization_id = $this->organization()->getKey();
        $document->name = $data['name'] ?? $file->getClientOriginalName();
        $document->disk = config('filesystems.default');
        $document->path = $path;
        $document->mime_type = $file->getClientMimeType();
        $document->size_bytes = $file->getSize();
        // So a later copy can be shown to be the same file, and a corrupted
        // one shown not to be.
        $document->checksum = hash_file('sha256', $file->getRealPath());
        $document->uploaded_by_id = auth()->id();

        // An identity document is personal data whether or not somebody
        // remembered to tick the box.
        if ($document->kind === Document::IDENTIFICATION) {
            $document->contains_personal_data = true;
        }

        $document->save();

        return (new DocumentResource($document))->response()->setStatusCode(201);
    }

    public function show(Document $document): DocumentResource
    {
        $this->authorize('view', $document);

        return new DocumentResource($document->load('uploader'));
    }

    /**
     * Stream the file.
     *
     * Through the application rather than by handing out a storage URL, so the
     * permission is checked on every read rather than once, at upload, by
     * whoever chose the folder.
     */
    public function download(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);

        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($document->path), 404, 'The file is no longer in storage.');

        return $disk->download($document->path, $document->name);
    }

    public function update(Request $request, Document $document): DocumentResource
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'kind' => $this->kindRule(),
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_guest_visible' => ['sometimes', 'boolean'],
            'is_owner_visible' => ['sometimes', 'boolean'],
            'contains_personal_data' => ['sometimes', 'boolean'],
            'retention_until' => ['sometimes', 'nullable', 'date'],
            'expires_on' => ['sometimes', 'nullable', 'date'],
        ]);

        $document->fill($data)->save();

        return new DocumentResource($document->fresh());
    }

    /**
     * Remove a document.
     *
     * Soft-deleted by default, so an accidental removal is recoverable. A
     * `purge` flag deletes the file from storage as well, which is what an
     * expired identity document needs and what an erasure request means — and
     * it is irreversible, so it is never the default.
     */
    public function destroy(Request $request, Document $document): JsonResponse
    {
        $this->authorize('delete', $document);

        $data = $request->validate([
            'purge' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $purge = (bool) ($data['purge'] ?? false);

        if ($purge) {
            Storage::disk($document->disk)->delete($document->path);
            $document->forceDelete();

            return response()->json([
                'message' => 'The document and its file have been permanently removed.',
            ]);
        }

        $document->delete();

        return response()->json([
            'message' => 'The document has been removed. The file is retained and can be restored.',
        ]);
    }

    /**
     * A fixed vocabulary rather than free text.
     *
     * The kind decides who may read the document — an identity document needs
     * the guest permission as well as the file one — so an operator inventing
     * a category must not be able to route a passport scan around that check
     * by calling it something else.
     *
     * @return list<mixed>
     */
    private function kindRule(): array
    {
        return ['sometimes', Rule::in([
            Document::CONTRACT,
            Document::IDENTIFICATION,
            Document::RECEIPT,
            Document::PHOTO,
            Document::CERTIFICATE,
            'other',
        ])];
    }
}
