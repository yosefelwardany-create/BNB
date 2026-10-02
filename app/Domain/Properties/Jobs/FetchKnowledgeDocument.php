<?php

declare(strict_types=1);

namespace App\Domain\Properties\Jobs;

use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Models\PropertyDocument;
use App\Domain\Properties\Services\KnowledgeDocumentFetcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Reads one property's knowledge document.
 *
 * Off the request because the document is somebody else's server: a Google Doc
 * that takes four seconds should not make saving an agent's settings take four
 * seconds, and a document that has gone missing should not make it fail.
 *
 * `$tries` is 1. The fetcher already records every outcome on the row — not
 * shared, unreachable, empty — so a retry here would turn one honest "this is
 * not shared" into three attempts and the same answer. The scheduled refresh is
 * what tries again, by which time somebody may have fixed the sharing.
 */
class FetchKnowledgeDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $documentId,
        public readonly string $organizationId,
    ) {
        $this->onQueue(config('pms.queues.default', 'default'));
    }

    public function handle(TenantContext $tenancy, KnowledgeDocumentFetcher $fetcher): void
    {
        $organization = $tenancy->withoutScope(
            fn (): ?Organization => Organization::query()->find($this->organizationId),
        );

        if ($organization === null) {
            return;
        }

        $tenancy->runAs($organization, function () use ($fetcher): void {
            $document = PropertyDocument::query()->find($this->documentId);

            if ($document !== null) {
                $fetcher->refresh($document);
            }
        });
    }

    /**
     * A job that died still leaves the row saying why.
     *
     * Otherwise a document sits on `pending` forever, which reads as a queue
     * that has not caught up rather than something needing attention.
     */
    public function failed(?Throwable $exception): void
    {
        PropertyDocument::query()
            ->withoutGlobalScope('organization')
            ->whereKey($this->documentId)
            ->where('status', PropertyDocument::STATUS_PENDING)
            ->update([
                'status' => PropertyDocument::STATUS_UNREACHABLE,
                'failure' => mb_substr($exception?->getMessage() ?? 'The document could not be read.', 0, 1000),
                'checked_at' => now(),
            ]);
    }
}
